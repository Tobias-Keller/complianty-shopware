<?php declare(strict_types=1);

namespace Complianty\Connector\Api;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Der einzige Ort, der mit Complianty spricht.
 *
 * - feste https-Basis, Override über COMPLIANTY_API_BASE nur außerhalb von prod;
 * - keine Redirects, 8 s Timeout, 1 MB Antwortlimit;
 * - das Token steht nur im Authorization-Header;
 * - Antworten werden gegen eine Whitelist validiert, Unbekanntes wird verworfen.
 */
class Client
{
    public const DEFAULT_BASE = 'https://app.complianty.de/api/v1/connect';
    public const TIMEOUT = 8;
    public const MAX_BODY = 1048576;

    private const STATES = ['ok', 'gaps', 'critical', 'pending'];
    private const SCAN_STATES = ['idle', 'queued', 'running'];
    private const PRIORITIES = ['critical', 'important', 'info'];
    private const MAX_TODOS = 10;
    private const MAX_TEXT = 300;
    private const MAX_HTML = 700000;
    private const MAX_RETRY_AFTER = 86400;
    private const JOB_ID = '/\A[A-Za-z0-9_-]{1,40}\z/';

    /**
     * Fehlercodes des Dienstes, die nach außen gereicht werden dürfen. Alles andere wird verworfen.
     */
    private const ERROR_CODES = [
        'unauthorized',
        'subscription_inactive',
        'feature_not_available',
        'insufficient_scope',
        'scan_already_running',
        'cooldown_active',
        'daily_scan_budget_reached',
        'target_recently_scanned',
        'rate_limited',
        'scanner_busy',
        'unsafe_url',
        'not_found',
    ];
    private const DATE = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

    private readonly string $base;

    public function __construct(
        string $environment,
        ?string $baseOverride,
        private readonly HttpClientInterface $http,
    ) {
        $this->base = self::resolveBase($environment, $baseOverride);
    }

    public static function resolveBase(string $environment, ?string $override): string
    {
        if ($environment === 'prod' || $override === null || trim($override) === '') {
            return self::DEFAULT_BASE;
        }

        $override = rtrim(trim($override), '/');
        $parts = parse_url($override);
        if (
            !\is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !\in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        ) {
            return self::DEFAULT_BASE;
        }

        return $override;
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    public function status(string $token): Result
    {
        return $this->fetch('GET', $token, '/status', self::validateStatus(...), self::TIMEOUT);
    }

    /**
     * Startet einen Scan. Immer vom Nutzer ausgelöst.
     */
    public function startScan(string $token): Result
    {
        return $this->fetch('POST', $token, '/scan', self::validateScanAccepted(...), self::TIMEOUT);
    }

    public function scanStatus(string $token): Result
    {
        return $this->fetch('GET', $token, '/scan-status', self::validateScanStatus(...), self::TIMEOUT);
    }

    public function dse(string $token, int $timeout = self::TIMEOUT): Result
    {
        return $this->fetch('GET', $token, '/dse', self::validateDse(...), $timeout);
    }

    /**
     * @param callable(mixed):?array<string,mixed> $validate
     */
    private function fetch(string $method, string $token, string $path, callable $validate, int $timeout): Result
    {
        try {
            $response = $this->http->request($method, $this->base . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                    'User-Agent' => 'CompliantyShopwareConnector/0.1.0',
                ],
                'timeout' => $timeout,
                'max_duration' => $timeout * 2,
                'max_redirects' => 0,
            ]);

            $code = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            return Result::failure(Result::UNREACHABLE);
        }

        if (\strlen($body) >= self::MAX_BODY) {
            return Result::failure(Result::INVALID);
        }

        // 200 für Lesezugriffe, 202 für den angenommenen Scan.
        if ($code === 200 || $code === 202) {
            $type = $headers['content-type'][0] ?? '';
            $data = preg_match('#^application/(?:[a-z0-9.+-]+\+)?json\s*(?:;|$)#i', $type) === 1
                ? $validate(json_decode($body, true, 8))
                : null;

            return $data === null ? Result::failure(Result::INVALID) : Result::ok($data);
        }

        return self::failureFor($code, $body, self::headerSeconds($headers['retry-after'][0] ?? null));
    }

    /**
     * @param int $headerRetry Wert des Retry-After-Headers in Sekunden, 0 wenn nicht vorhanden
     */
    private static function failureFor(int $status, string $body, int $headerRetry): Result
    {
        $decoded = json_decode($body, true, 8);
        $detail = \is_array($decoded) ? ($decoded['detail'] ?? null) : null;

        // Der Dienst liefert {"detail": {"detail": "<code>", ...}}, tolerant auch {"detail": "<code>"}.
        $code = \is_array($detail) ? ($detail['detail'] ?? null) : $detail;
        $code = \is_string($code) && \in_array($code, self::ERROR_CODES, true) ? $code : '';

        $retry = \is_array($detail) && \is_int($detail['retry_after'] ?? null) ? $detail['retry_after'] : $headerRetry;
        $retry = max(0, min(self::MAX_RETRY_AFTER, $retry));

        return match (true) {
            $status === 401 => Result::failure(Result::UNAUTHORIZED, 'unauthorized'),
            $status === 403 => Result::failure(self::forbiddenKind($code), $code),
            $status === 409 => Result::failure(Result::CONFLICT, $code),
            $status === 429 => self::rateLimited($code, $retry),
            $status >= 500 => Result::failure(Result::UNREACHABLE, $code, $retry),
            $status >= 400 && $code !== '' && $code !== 'not_found' => Result::failure(Result::REJECTED, $code),
            default => Result::failure(Result::INVALID, $code),
        };
    }

    private static function rateLimited(string $code, int $retry): Result
    {
        return match ($code) {
            'cooldown_active', 'target_recently_scanned' => Result::failure(Result::COOLDOWN, $code, $retry),
            'daily_scan_budget_reached' => Result::failure(Result::BUDGET, $code, $retry),
            default => Result::failure(Result::RATE_LIMITED, 'rate_limited', $retry),
        };
    }

    private static function headerSeconds(?string $value): int
    {
        return $value !== null && preg_match('/\A\d{1,6}\z/', $value) === 1 ? (int) $value : 0;
    }

    private static function forbiddenKind(string $code): string
    {
        return match ($code) {
            'subscription_inactive' => Result::SUBSCRIPTION_INACTIVE,
            'feature_not_available' => Result::NO_FEATURE,
            default => Result::FORBIDDEN,
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function validateStatus(mixed $data): ?array
    {
        if (!\is_array($data)) {
            return null;
        }

        $state = $data['status'] ?? null;
        $scanState = $data['scan_state'] ?? null;
        $critical = $data['open_critical'] ?? null;
        $important = $data['open_important'] ?? null;
        $last = $data['last_scanned_at'] ?? null;

        if (
            !\in_array($state, self::STATES, true) || !\in_array($scanState, self::SCAN_STATES, true)
            || !\is_int($critical) || $critical < 0 || !\is_int($important) || $important < 0
            || ($last !== null && (!\is_string($last) || preg_match(self::DATE, $last) !== 1))
            || !\is_array($data['todos'] ?? [])
        ) {
            return null;
        }

        $todos = [];
        foreach (\array_slice($data['todos'] ?? [], 0, self::MAX_TODOS) as $item) {
            if (
                !\is_array($item)
                || !\is_string($item['title'] ?? null) || !\is_string($item['text'] ?? null)
                || !\in_array($item['priority'] ?? null, self::PRIORITIES, true)
            ) {
                return null;
            }
            $todos[] = [
                'priority' => $item['priority'],
                'title' => mb_substr($item['title'], 0, self::MAX_TEXT),
                'text' => mb_substr($item['text'], 0, self::MAX_TEXT),
            ];
        }

        return [
            'status' => $state,
            'open_critical' => $critical,
            'open_important' => $important,
            'last_scanned_at' => $last,
            'scan_state' => $scanState,
            'scan_trigger_allowed' => ($data['scan_trigger_allowed'] ?? false) === true,
            'todos' => $todos,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function validateScanAccepted(mixed $data): ?array
    {
        if (!\is_array($data)) {
            return null;
        }

        $jobId = $data['job_id'] ?? null;
        $state = $data['scan_state'] ?? null;
        if (!\is_string($jobId) || preg_match(self::JOB_ID, $jobId) !== 1 || !\in_array($state, self::SCAN_STATES, true)) {
            return null;
        }

        return ['job_id' => $jobId, 'scan_state' => $state];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function validateScanStatus(mixed $data): ?array
    {
        if (!\is_array($data)) {
            return null;
        }

        $state = $data['scan_state'] ?? null;
        $last = $data['last_scanned_at'] ?? null;
        $error = $data['last_scan_error'] ?? null;
        if (
            !\in_array($state, self::SCAN_STATES, true)
            || ($last !== null && (!\is_string($last) || preg_match(self::DATE, $last) !== 1))
            || ($error !== null && !\is_string($error))
        ) {
            return null;
        }

        return [
            'scan_state' => $state,
            'last_scanned_at' => $last,
            'last_scan_error' => $error === null ? null : mb_substr($error, 0, self::MAX_TEXT),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function validateDse(mixed $data): ?array
    {
        if (!\is_array($data)) {
            return null;
        }

        $state = $data['state'] ?? null;
        if (!\in_array($state, ['active', 'lapsed', 'not_approved', 'missing'], true)) {
            return null;
        }

        $dates = [];
        foreach (['approved_at', 'generated_at'] as $key) {
            $value = $data[$key] ?? null;
            if ($value !== null && (!\is_string($value) || preg_match(self::DATE, $value) !== 1)) {
                return null;
            }
            $dates[$key] = $value;
        }

        $html = null;
        if ($state === 'active' || $state === 'lapsed') {
            $raw = $data['html'] ?? null;
            if (
                !\is_string($raw) || \strlen($raw) > self::MAX_HTML
                || ($state === 'active' && (trim($raw) === '' || $dates['approved_at'] === null))
            ) {
                return null;
            }
            $html = $raw;
        }

        return ['state' => $state, 'html' => $html] + $dates;
    }
}
