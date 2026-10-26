<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Controller\ConnectionController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(ConnectionController::class)]
final class ScanControllerTest extends AdminApiIntegrationTestCase
{
    public function testBothRoutesRequireAuthentication(): void
    {
        foreach ([['POST', '/scan'], ['GET', '/scan-status']] as [$method, $path]) {
            [$status] = $this->call($method, $path, [], authorized: false);

            static::assertSame(401, $status, $method . ' ' . $path);
        }
        static::assertSame([], $this->api->requests());
    }

    public function testWithoutTokenNothingIsRequested(): void
    {
        foreach ([['POST', '/scan'], ['GET', '/scan-status']] as [$method, $path]) {
            [$status, $body] = $this->call($method, $path, []);

            static::assertSame(200, $status);
            static::assertSame('no_token', $body['state']);
        }
        static::assertSame([], $this->api->requests());
    }

    public function testStartScanPostsWithTheBearerToken(): void
    {
        $this->storeToken();
        $this->api->respond('/scan', 202, ['job_id' => 'job-1', 'scan_state' => 'queued']);

        [$status, $body, $raw] = $this->call('POST', '/scan', []);

        static::assertSame(200, $status);
        static::assertSame('ok', $body['state']);
        static::assertSame('queued', $body['scan_state']);
        static::assertSame(0, $body['retry_after']);
        static::assertStringNotContainsString(self::TOKEN, $raw);

        $requests = $this->api->requests();
        static::assertCount(1, $requests);
        static::assertSame('POST', $requests[0]['method']);
        static::assertSame('/scan', $requests[0]['route']);
        static::assertSame('Bearer ' . self::TOKEN, $requests[0]['authorization']);
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>, string, string, int}>
     */
    public static function scanOutcomes(): iterable
    {
        yield 'already running' => [409, ['detail' => ['detail' => 'scan_already_running', 'status' => 'running']], 'conflict', 'scan_already_running', 0];
        yield 'cooldown' => [429, ['detail' => ['detail' => 'cooldown_active', 'retry_after' => 120]], 'cooldown', 'cooldown_active', 120];
        yield 'target recently scanned' => [429, ['detail' => ['detail' => 'target_recently_scanned']], 'cooldown', 'target_recently_scanned', 0];
        yield 'daily budget' => [429, ['detail' => ['detail' => 'daily_scan_budget_reached', 'budget' => 5, 'used' => 5]], 'budget', 'daily_scan_budget_reached', 0];
        yield 'token without scan right' => [403, ['detail' => ['detail' => 'insufficient_scope']], 'forbidden', 'insufficient_scope', 0];
        yield 'subscription inactive' => [403, ['detail' => ['detail' => 'subscription_inactive']], 'subscription_inactive', 'subscription_inactive', 0];
        yield 'address not scannable' => [400, ['detail' => 'unsafe_url'], 'rejected', 'unsafe_url', 0];
        yield 'scanner busy' => [503, ['detail' => ['detail' => 'scanner_busy']], 'unreachable', 'scanner_busy', 0];
        yield 'revoked token' => [401, ['detail' => ['detail' => 'unauthorized']], 'unauthorized', 'unauthorized', 0];
        yield 'plain rate limit' => [429, ['detail' => ['detail' => 'rate_limited']], 'rate_limited', 'rate_limited', 0];
        yield 'hostile code' => [400, ['detail' => ['detail' => '<script>alert(1)</script>']], 'invalid', '', 0];
    }

    /**
     * @param array<string, mixed> $serviceBody
     */
    #[DataProvider('scanOutcomes')]
    public function testStartScanReportsOutcomesAsStateNotAsHttpError(int $httpStatus, array $serviceBody, string $state, string $code, int $retryAfter): void
    {
        $this->storeToken();
        $this->api->respond('/scan', $httpStatus, $serviceBody);

        [$status, $body, $raw] = $this->call('POST', '/scan', []);

        static::assertSame(200, $status, 'Erwartbare Zustände sind kein HTTP-Fehler der Admin-API.');
        static::assertSame($state, $body['state']);
        static::assertSame($code, $body['code']);
        static::assertSame($retryAfter, $body['retry_after']);
        static::assertNull($body['scan_state']);
        static::assertStringNotContainsString('<script', $raw);
    }

    public function testGarbageAnswerToStartScanIsInvalid(): void
    {
        $this->storeToken();
        $this->api->respond('/scan', 202, '<html>nope</html>', 'text/html');

        [, $body] = $this->call('POST', '/scan', []);

        static::assertSame('invalid', $body['state']);
    }

    public function testScanStatusPassesTheStateThrough(): void
    {
        $this->storeToken();
        $this->api->respond('/scan-status', 200, ['scan_state' => 'running', 'last_scanned_at' => '2026-08-04T12:18:49Z', 'last_scan_error' => null]);

        [$status, $body] = $this->call('GET', '/scan-status');

        static::assertSame(200, $status);
        static::assertSame('ok', $body['state']);
        static::assertSame('running', $body['scan_state']);
        static::assertSame('2026-08-04T12:18:49Z', $body['last_scanned_at']);
        static::assertNull($body['last_scan_error']);
        static::assertSame('GET', $this->api->requests()[0]['method']);
    }

    public function testScanStatusSurfacesTheLastError(): void
    {
        $this->storeToken();
        $this->api->respond('/scan-status', 200, ['scan_state' => 'idle', 'last_scanned_at' => null, 'last_scan_error' => 'Timeout beim Laden']);

        [, $body] = $this->call('GET', '/scan-status');

        static::assertSame('idle', $body['scan_state']);
        static::assertSame('Timeout beim Laden', $body['last_scan_error']);
    }

    public function testScanStatusMapsServiceFailures(): void
    {
        $this->storeToken();
        $this->api->respond('/scan-status', 500, 'boom');

        [$status, $body] = $this->call('GET', '/scan-status');

        static::assertSame(200, $status);
        static::assertSame('unreachable', $body['state']);
        static::assertNull($body['scan_state']);
    }

    public function testReadOnlyUserCanPollButNotStartAScan(): void
    {
        $this->storeToken();
        $this->api->respond('/scan', 202, ['job_id' => 'job-1', 'scan_state' => 'queued']);
        $this->api->respond('/scan-status', 200, ['scan_state' => 'idle', 'last_scanned_at' => null]);
        $readOnly = ['system_config:read'];

        [$poll] = $this->call('GET', '/scan-status', permissions: $readOnly);
        static::assertSame(200, $poll);

        [$start] = $this->call('POST', '/scan', [], permissions: $readOnly);
        static::assertSame(403, $start);

        static::assertSame(['/scan-status'], $this->api->routes(), 'Ein Nutzer ohne Schreibrecht darf keinen Scan auslösen.');
    }
}
