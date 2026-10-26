<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Unit\Api;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(Client::class)]
#[CoversClass(Result::class)]
final class ClientTest extends TestCase
{
    private const TOKEN = 'clp_site_FakeTokenForTests0123456789abcdefghijk';

    public function testStatusOk(): void
    {
        $result = self::client(self::json(self::statusPayload()))->status(self::TOKEN);

        static::assertTrue($result->isOk());
        static::assertSame('critical', $result->data['status'] ?? null);
        static::assertSame(2, $result->data['open_critical'] ?? null);
        static::assertSame('Titel', $result->data['todos'][0]['title'] ?? null);
    }

    public function testUnknownFieldsAreDropped(): void
    {
        $payload = self::statusPayload() + ['internal' => 'secret'];
        $payload['todos'][0]['internal_id'] = 'x';

        $result = self::client(self::json($payload))->status(self::TOKEN);

        static::assertIsArray($result->data);
        static::assertArrayNotHasKey('internal', $result->data);
        static::assertArrayNotHasKey('id', $result->data['todos'][0]);
        static::assertArrayHasKey('scan_trigger_allowed', $result->data, 'Dieses Feld steuert den Scan-Button und wird bewusst durchgereicht.');
    }

    public function testTokenTravelsOnlyInTheAuthorizationHeader(): void
    {
        /** @var array{method: string, url: string, options: array<string, mixed>} $seen */
        $seen = ['method' => '', 'url' => '', 'options' => []];
        $client = self::client(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return self::json(self::statusPayload());
        });

        $client->status(self::TOKEN);

        static::assertSame('GET', $seen['method']);
        static::assertSame('http://backend.test/api/v1/connect/status', $seen['url']);
        static::assertStringNotContainsString(self::TOKEN, $seen['url']);
        static::assertContains('Authorization: Bearer ' . self::TOKEN, $seen['options']['headers']);
        static::assertArrayNotHasKey('body', $seen['options']);
        static::assertSame(0, $seen['options']['max_redirects']);
    }

    public function testDseRequestsTheDsePath(): void
    {
        $url = '';
        $client = self::client(static function (string $method, string $u) use (&$url): MockResponse {
            $url = $u;

            return self::json(['state' => 'missing', 'html' => null, 'approved_at' => null, 'generated_at' => null]);
        });

        $client->dse(self::TOKEN);

        static::assertSame('http://backend.test/api/v1/connect/dse', $url);
    }

    #[DataProvider('failureStatuses')]
    public function testHttpErrorMapping(int $status, string $body, string $expected): void
    {
        $result = self::client(self::json($body, $status))->status(self::TOKEN);

        static::assertFalse($result->isOk());
        static::assertSame($expected, $result->kind);
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function failureStatuses(): iterable
    {
        yield '401' => [401, '{"detail":{"detail":"unauthorized"}}', Result::UNAUTHORIZED];
        yield '403 subscription nested' => [403, '{"detail":{"detail":"subscription_inactive"}}', Result::SUBSCRIPTION_INACTIVE];
        yield '403 subscription flat' => [403, '{"detail":"subscription_inactive"}', Result::SUBSCRIPTION_INACTIVE];
        yield '403 feature' => [403, '{"detail":{"detail":"feature_not_available"}}', Result::NO_FEATURE];
        yield '403 scope' => [403, '{"detail":{"detail":"insufficient_scope"}}', Result::FORBIDDEN];
        yield '403 unknown code' => [403, '{"detail":{"detail":"<script>"}}', Result::FORBIDDEN];
        yield '403 not json' => [403, 'Forbidden', Result::FORBIDDEN];
        yield '429' => [429, '{}', Result::RATE_LIMITED];
        yield '500' => [500, '{}', Result::UNREACHABLE];
        yield '503' => [503, 'upstream down', Result::UNREACHABLE];
        yield '404' => [404, '{}', Result::INVALID];
        yield '302 redirect is never followed' => [302, '', Result::INVALID];
        yield '204 is not a valid status answer' => [204, '', Result::INVALID];
    }

    public function testNetworkErrorIsUnreachable(): void
    {
        $result = self::client(new MockResponse('', ['error' => 'Connection refused']))->status(self::TOKEN);

        static::assertSame(Result::UNREACHABLE, $result->kind);
    }

    public function testNonJsonContentTypeIsInvalid(): void
    {
        $result = self::client(self::json(self::statusPayload(), 200, 'text/html'))->status(self::TOKEN);

        static::assertSame(Result::INVALID, $result->kind);
    }

    public function testJsonSuffixContentTypeIsAccepted(): void
    {
        $result = self::client(self::json(self::statusPayload(), 200, 'application/problem+json; charset=utf-8'))->status(self::TOKEN);

        static::assertTrue($result->isOk());
    }

    public function testBrokenJsonIsInvalid(): void
    {
        static::assertSame(Result::INVALID, self::client(self::json('{"status":'))->status(self::TOKEN)->kind);
    }

    public function testOversizedBodyIsInvalid(): void
    {
        $body = json_encode(['status' => 'ok', 'pad' => str_repeat('a', Client::MAX_BODY)], \JSON_THROW_ON_ERROR);

        static::assertSame(Result::INVALID, self::client(self::json($body))->status(self::TOKEN)->kind);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('invalidStatusPayloads')]
    public function testStatusSchemaViolationsAreInvalid(callable $mutate): void
    {
        $result = self::client(self::json($mutate(self::statusPayload())))->status(self::TOKEN);

        static::assertSame(Result::INVALID, $result->kind);
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public static function invalidStatusPayloads(): iterable
    {
        yield 'unknown status' => [static fn (array $p): array => ['status' => 'purple'] + $p];
        yield 'unknown scan state' => [static fn (array $p): array => ['scan_state' => 'exploding'] + $p];
        yield 'negative count' => [static fn (array $p): array => ['open_critical' => -1] + $p];
        yield 'count as string' => [static fn (array $p): array => ['open_critical' => '2'] + $p];
        yield 'bad date' => [static fn (array $p): array => ['last_scanned_at' => 'gestern'] + $p];
        yield 'date as number' => [static fn (array $p): array => ['last_scanned_at' => 1700000000] + $p];
        yield 'todos not a list' => [static fn (array $p): array => ['todos' => 'x'] + $p];
        yield 'todo without title' => [static fn (array $p): array => ['todos' => [['priority' => 'critical', 'text' => 't']]] + $p];
        yield 'todo with unknown priority' => [static fn (array $p): array => ['todos' => [['priority' => 'urgent', 'title' => 'a', 'text' => 'b']]] + $p];
        yield 'body is a list' => [static fn (array $p): array => ['x', 'y']];
    }

    public function testNullLastScanIsAllowed(): void
    {
        $payload = ['last_scanned_at' => null] + self::statusPayload();

        $data = self::client(self::json($payload))->status(self::TOKEN)->data;

        static::assertIsArray($data);
        static::assertArrayHasKey('last_scanned_at', $data);
        static::assertNull($data['last_scanned_at']);
    }

    public function testTodosAreCappedAndTruncated(): void
    {
        $todo = ['priority' => 'important', 'title' => str_repeat('t', 1000), 'text' => str_repeat('x', 1000)];
        $payload = ['todos' => array_fill(0, 25, $todo)] + self::statusPayload();

        $data = self::client(self::json($payload))->status(self::TOKEN)->data;

        static::assertIsArray($data);
        static::assertCount(10, $data['todos']);
        static::assertSame(300, mb_strlen($data['todos'][0]['title']));
        static::assertSame(300, mb_strlen($data['todos'][0]['text']));
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('validDsePayloads')]
    public function testDseValid(array $payload): void
    {
        $result = self::client(self::json($payload))->dse(self::TOKEN);

        static::assertTrue($result->isOk());
        static::assertSame($payload['state'], $result->data['state'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validDsePayloads(): iterable
    {
        yield 'active' => [['state' => 'active', 'html' => '<p>x</p>', 'approved_at' => '2026-10-26T08:28:16.444611Z', 'generated_at' => '2026-10-25T08:00:00Z']];
        yield 'lapsed has html without approval' => [['state' => 'lapsed', 'html' => '<p>Hinweis</p>', 'approved_at' => null, 'generated_at' => null]];
        yield 'not approved' => [['state' => 'not_approved', 'html' => null, 'approved_at' => null, 'generated_at' => '2026-10-25T08:00:00Z']];
        yield 'missing' => [['state' => 'missing', 'html' => null, 'approved_at' => null, 'generated_at' => null]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidDsePayloads')]
    public function testDseInvalid(array $payload): void
    {
        static::assertSame(Result::INVALID, self::client(self::json($payload))->dse(self::TOKEN)->kind);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidDsePayloads(): iterable
    {
        yield 'unknown state' => [['state' => 'draft', 'html' => null, 'approved_at' => null, 'generated_at' => null]];
        yield 'active without html' => [['state' => 'active', 'html' => null, 'approved_at' => '2026-10-26T08:28:16Z', 'generated_at' => null]];
        yield 'active with blank html' => [['state' => 'active', 'html' => "  \n", 'approved_at' => '2026-10-26T08:28:16Z', 'generated_at' => null]];
        yield 'active without approval date' => [['state' => 'active', 'html' => '<p>x</p>', 'approved_at' => null, 'generated_at' => null]];
        yield 'html is not a string' => [['state' => 'active', 'html' => ['x'], 'approved_at' => '2026-10-26T08:28:16Z', 'generated_at' => null]];
        yield 'bad approval date' => [['state' => 'not_approved', 'html' => null, 'approved_at' => 'morgen', 'generated_at' => null]];
        yield 'html above limit' => [['state' => 'active', 'html' => str_repeat('a', 700001), 'approved_at' => '2026-10-26T08:28:16Z', 'generated_at' => null]];
    }

    public function testDseHtmlIsIgnoredForStatesWithoutPolicy(): void
    {
        $payload = ['state' => 'not_approved', 'html' => '<p>Entwurf</p>', 'approved_at' => null, 'generated_at' => null];

        $data = self::client(self::json($payload))->dse(self::TOKEN)->data;

        static::assertIsArray($data);
        static::assertArrayHasKey('html', $data);
        static::assertNull($data['html']);
    }

    #[DataProvider('bases')]
    public function testResolveBase(string $environment, ?string $override, string $expected): void
    {
        static::assertSame($expected, Client::resolveBase($environment, $override));
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function bases(): iterable
    {
        $default = Client::DEFAULT_BASE;

        yield 'prod ignores override' => ['prod', 'https://evil.example/api', $default];
        yield 'no override' => ['dev', null, $default];
        yield 'blank override' => ['dev', '  ', $default];
        yield 'dev accepts http' => ['dev', 'http://host.docker.internal:8003/api/v1/connect', 'http://host.docker.internal:8003/api/v1/connect'];
        yield 'dev accepts https and trims slash' => ['dev', ' https://staging.example/api/ ', 'https://staging.example/api'];
        yield 'rejects credentials' => ['dev', 'https://user:pw@evil.example/api', $default];
        yield 'rejects query' => ['dev', 'https://evil.example/api?x=1', $default];
        yield 'rejects fragment' => ['dev', 'https://evil.example/api#x', $default];
        yield 'rejects other schemes' => ['dev', 'ftp://evil.example/api', $default];
        yield 'rejects javascript' => ['dev', 'javascript:alert(1)', $default];
        yield 'rejects missing host' => ['dev', 'https:///api', $default];
        yield 'rejects garbage' => ['dev', 'not a url', $default];
    }

    public function testBaseUrlAccessor(): void
    {
        static::assertSame(Client::DEFAULT_BASE, self::client(self::json([]), 'prod')->baseUrl());
    }

    public function testResultTokenAccepted(): void
    {
        static::assertTrue(Result::ok([])->tokenAccepted());
        static::assertTrue(Result::failure(Result::SUBSCRIPTION_INACTIVE)->tokenAccepted());
        static::assertTrue(Result::failure(Result::NO_FEATURE)->tokenAccepted());
        static::assertTrue(Result::failure(Result::FORBIDDEN)->tokenAccepted());
        static::assertFalse(Result::failure(Result::UNAUTHORIZED)->tokenAccepted());
        static::assertFalse(Result::failure(Result::UNREACHABLE)->tokenAccepted());
        static::assertFalse(Result::failure(Result::NO_TOKEN)->tokenAccepted());
        static::assertFalse(Result::failure(Result::INVALID)->tokenAccepted());
    }

    #[DataProvider('scanTriggerFlags')]
    public function testScanTriggerAllowedIsStrictlyBoolean(mixed $value, bool $expected): void
    {
        $payload = ['scan_trigger_allowed' => $value] + self::statusPayload();

        $data = self::client(self::json($payload))->status(self::TOKEN)->data;

        static::assertIsArray($data);
        static::assertSame($expected, $data['scan_trigger_allowed']);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function scanTriggerFlags(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'string true is not true' => ['true', false];
        yield 'one is not true' => [1, false];
        yield 'null' => [null, false];
    }

    public function testStartScanPostsAndAcceptsA202(): void
    {
        $seen = ['method' => '', 'url' => '', 'headers' => []];
        $client = self::client(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'headers' => $options['headers']];

            return self::json(['job_id' => 'job_1-a', 'scan_state' => 'queued'], 202);
        });

        $result = $client->startScan(self::TOKEN);

        static::assertTrue($result->isOk());
        static::assertSame(['job_id' => 'job_1-a', 'scan_state' => 'queued'], $result->data);
        static::assertSame('POST', $seen['method']);
        static::assertSame('http://backend.test/api/v1/connect/scan', $seen['url']);
        static::assertContains('Authorization: Bearer ' . self::TOKEN, $seen['headers']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidScanAccepted')]
    public function testStartScanRejectsMalformedAcceptance(array $payload): void
    {
        static::assertSame(Result::INVALID, self::client(self::json($payload, 202))->startScan(self::TOKEN)->kind);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidScanAccepted(): iterable
    {
        yield 'job id with path characters' => [['job_id' => '../etc', 'scan_state' => 'queued']];
        yield 'job id too long' => [['job_id' => str_repeat('a', 41), 'scan_state' => 'queued']];
        yield 'job id missing' => [['scan_state' => 'queued']];
        yield 'unknown state' => [['job_id' => 'a', 'scan_state' => 'exploding']];
        yield 'job id as number' => [['job_id' => 5, 'scan_state' => 'queued']];
    }

    public function testScanStatusOk(): void
    {
        $payload = ['scan_state' => 'running', 'last_scanned_at' => '2026-08-04T12:18:49Z', 'last_scan_error' => null];

        $result = self::client(self::json($payload))->scanStatus(self::TOKEN);

        static::assertTrue($result->isOk());
        static::assertSame($payload, $result->data);
    }

    public function testScanStatusUsesGet(): void
    {
        $seen = '';
        $client = self::client(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen = $method . ' ' . $url;

            return self::json(['scan_state' => 'idle', 'last_scanned_at' => null]);
        });

        $client->scanStatus(self::TOKEN);

        static::assertSame('GET http://backend.test/api/v1/connect/scan-status', $seen);
    }

    public function testScanStatusTruncatesTheLastError(): void
    {
        $payload = ['scan_state' => 'idle', 'last_scanned_at' => null, 'last_scan_error' => str_repeat('x', 1000)];

        $data = self::client(self::json($payload))->scanStatus(self::TOKEN)->data;

        static::assertIsArray($data);
        static::assertSame(300, mb_strlen($data['last_scan_error']));
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidScanStatus')]
    public function testScanStatusRejectsMalformedAnswers(array $payload): void
    {
        static::assertSame(Result::INVALID, self::client(self::json($payload))->scanStatus(self::TOKEN)->kind);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidScanStatus(): iterable
    {
        yield 'unknown state' => [['scan_state' => 'done', 'last_scanned_at' => null]];
        yield 'bad date' => [['scan_state' => 'idle', 'last_scanned_at' => 'gestern']];
        yield 'error not a string' => [['scan_state' => 'idle', 'last_scanned_at' => null, 'last_scan_error' => ['x']]];
        yield 'empty' => [[]];
    }

    /**
     * @param list<string> $headers
     */
    #[DataProvider('scanFailures')]
    public function testStartScanFailureMapping(int $status, string $body, array $headers, string $kind, string $code, int $retry): void
    {
        $result = self::client(self::json($body, $status, headers: $headers))->startScan(self::TOKEN);

        static::assertSame($kind, $result->kind);
        static::assertSame($code, $result->code);
        static::assertSame($retry, $result->retryAfter);
    }

    /**
     * @return iterable<string, array{int, string, list<string>, string, string, int}>
     */
    public static function scanFailures(): iterable
    {
        yield 'already running' => [409, '{"detail":{"detail":"scan_already_running","status":"running"}}', [], Result::CONFLICT, 'scan_already_running', 0];
        yield 'cooldown with retry in body' => [429, '{"detail":{"detail":"cooldown_active","retry_after":120}}', [], Result::COOLDOWN, 'cooldown_active', 120];
        yield 'cooldown with retry header only' => [429, '{"detail":{"detail":"cooldown_active"}}', ['Retry-After: 300'], Result::COOLDOWN, 'cooldown_active', 300];
        yield 'body wins over header' => [429, '{"detail":{"detail":"cooldown_active","retry_after":60}}', ['Retry-After: 300'], Result::COOLDOWN, 'cooldown_active', 60];
        yield 'target recently scanned' => [429, '{"detail":{"detail":"target_recently_scanned"}}', [], Result::COOLDOWN, 'target_recently_scanned', 0];
        yield 'daily budget' => [429, '{"detail":{"detail":"daily_scan_budget_reached","budget":5,"used":5}}', ['Retry-After: 3600'], Result::BUDGET, 'daily_scan_budget_reached', 3600];
        yield 'plain rate limit' => [429, '{"detail":{"detail":"rate_limited"}}', ['Retry-After: 30'], Result::RATE_LIMITED, 'rate_limited', 30];
        yield 'unknown 429 code' => [429, '{"detail":"whatever"}', [], Result::RATE_LIMITED, 'rate_limited', 0];
        yield 'retry is clamped to a day' => [429, '{"detail":{"detail":"cooldown_active","retry_after":99999999}}', [], Result::COOLDOWN, 'cooldown_active', 86400];
        yield 'negative retry is zero' => [429, '{"detail":{"detail":"cooldown_active","retry_after":-5}}', [], Result::COOLDOWN, 'cooldown_active', 0];
        yield 'retry as string is ignored' => [429, '{"detail":{"detail":"cooldown_active","retry_after":"120"}}', [], Result::COOLDOWN, 'cooldown_active', 0];
        yield 'garbage retry header is ignored' => [429, '{"detail":{"detail":"cooldown_active"}}', ['Retry-After: soon'], Result::COOLDOWN, 'cooldown_active', 0];
        yield 'token has no scan scope' => [403, '{"detail":{"detail":"insufficient_scope"}}', [], Result::FORBIDDEN, 'insufficient_scope', 0];
        yield 'subscription inactive' => [403, '{"detail":{"detail":"subscription_inactive"}}', [], Result::SUBSCRIPTION_INACTIVE, 'subscription_inactive', 0];
        yield 'unsafe url' => [400, '{"detail":"unsafe_url"}', [], Result::REJECTED, 'unsafe_url', 0];
        yield 'scanner busy' => [503, '{"detail":{"detail":"scanner_busy"}}', ['Retry-After: 45'], Result::UNREACHABLE, 'scanner_busy', 45];
        yield 'server error without code' => [500, 'boom', [], Result::UNREACHABLE, '', 0];
        yield 'not found' => [404, '{"detail":{"detail":"not_found"}}', [], Result::INVALID, 'not_found', 0];
        yield 'unknown client error' => [422, '{"detail":"mystery"}', [], Result::INVALID, '', 0];
        yield 'hostile code is dropped' => [400, '{"detail":{"detail":"<script>alert(1)</script>"}}', [], Result::INVALID, '', 0];
        yield 'revoked token' => [401, '{"detail":{"detail":"unauthorized"}}', [], Result::UNAUTHORIZED, 'unauthorized', 0];
    }

    public function testResultCarriesCodeAndRetryAfter(): void
    {
        $result = Result::failure(Result::COOLDOWN, 'cooldown_active', 90);

        static::assertFalse($result->isOk());
        static::assertSame('cooldown_active', $result->code);
        static::assertSame(90, $result->retryAfter);
        static::assertNull($result->data);
        static::assertSame(['status' => 'ok'], Result::ok(['status' => 'ok'])->data);
        static::assertSame(0, Result::ok([])->retryAfter);
    }

    /**
     * @return array<string, mixed>
     */
    private static function statusPayload(): array
    {
        return [
            'status' => 'critical',
            'open_critical' => 2,
            'open_important' => 1,
            'last_scanned_at' => '2026-08-04T12:18:49.394561Z',
            'scan_state' => 'idle',
            'scan_trigger_allowed' => false,
            'todos' => [
                ['id' => 'a', 'type' => 'X', 'priority' => 'critical', 'title' => 'Titel', 'text' => 'Text'],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|string $body
     * @param list<string> $headers
     */
    private static function json(array|string $body, int $status = 200, string $type = 'application/json', array $headers = []): MockResponse
    {
        return new MockResponse(\is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type: ' . $type, ...$headers],
        ]);
    }

    private static function client(MockResponse|callable $response, string $env = 'dev', ?string $override = 'http://backend.test/api/v1/connect'): Client
    {
        return new Client($env, $override, new MockHttpClient($response));
    }
}
