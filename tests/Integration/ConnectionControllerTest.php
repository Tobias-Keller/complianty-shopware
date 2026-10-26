<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Controller\ConnectionController;
use Complianty\Connector\Service\DseStore;
use Complianty\Connector\Service\TokenStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ConnectionController::class)]
final class ConnectionControllerTest extends AdminApiIntegrationTestCase
{
    public function testStatusWithoutTokenDoesNotContactTheService(): void
    {
        [$status, $body] = $this->call('GET', '/status');

        static::assertSame(200, $status);
        static::assertSame('no_token', $body['state']);
        static::assertNull($body['status']);
        static::assertNull($body['token_hint']);
        static::assertSame([], $this->api->requests());
    }

    public function testEveryRouteRequiresAuthentication(): void
    {
        foreach ([['GET', '/status'], ['POST', '/connect'], ['POST', '/disconnect'], ['POST', '/dse/sync']] as [$method, $path]) {
            [$status] = $this->call($method, $path, [], authorized: false);

            static::assertSame(401, $status, $method . ' ' . $path);
        }
        static::assertSame([], $this->api->requests());
    }

    public function testConnectRejectsMalformedTokenWithoutCallingTheService(): void
    {
        [$status, $body] = $this->call('POST', '/connect', ['token' => 'abc']);

        static::assertSame(422, $status);
        static::assertSame('malformed', $body['state']);
        static::assertSame([], $this->api->requests());
        static::assertNull($this->service(TokenStore::class)->get());
    }

    public function testConnectStoresTheTokenEncryptedAndNeverReturnsIt(): void
    {
        $this->api->respond('/status', 200, self::statusPayload());
        $this->api->respond('/dse', 200, self::dsePayload());

        [$status, $body, $raw] = $this->call('POST', '/connect', ['token' => self::TOKEN]);

        static::assertSame(200, $status);
        static::assertSame('ok', $body['state']);
        static::assertSame('clp_site_Fake…', $body['token_hint']);
        static::assertStringNotContainsString(self::TOKEN, $raw);

        static::assertSame(self::TOKEN, $this->service(TokenStore::class)->get());
        $stored = $this->service(SystemConfigService::class)->get(TokenStore::CONFIG_KEY);
        static::assertIsString($stored);
        static::assertStringNotContainsString('clp_site_', $stored);
        static::assertStringNotContainsString(self::TOKEN, (string) base64_decode($stored, true));
    }

    public function testConnectSendsTheTokenOnlyAsBearerHeader(): void
    {
        $this->api->respond('/status', 200, self::statusPayload());
        $this->api->respond('/dse', 200, self::dsePayload());

        $this->call('POST', '/connect', ['token' => self::TOKEN]);

        $requests = $this->api->requests();
        static::assertSame(['/status', '/dse'], array_column($requests, 'route'));
        foreach ($requests as $request) {
            static::assertSame('GET', $request['method']);
            static::assertSame('Bearer ' . self::TOKEN, $request['authorization']);
            static::assertSame('', $request['query']);
        }
    }

    public function testConnectFetchesTheDseRightAway(): void
    {
        $this->api->respond('/status', 200, self::statusPayload());
        $this->api->respond('/dse', 200, self::dsePayload('<h2>Datenschutz</h2><script>alert(1)</script>'));

        [, $body] = $this->call('POST', '/connect', ['token' => self::TOKEN]);

        static::assertSame('active', $body['dse']['state']);
        static::assertTrue($body['dse']['has_copy']);
        $record = $this->service(DseStore::class)->get();
        static::assertNotNull($record);
        static::assertStringContainsString('<h2>Datenschutz</h2>', $record['html']);
        static::assertStringNotContainsString('script', $record['html']);
    }

    public function testConnectDiscardsTheCopyOfAPreviousConnection(): void
    {
        $this->storeToken(self::OTHER_TOKEN);
        $this->service(DseStore::class)->apply(self::dsePayload('<p>Andere Website</p>'), 1000);
        $this->api->respond('/status', 200, self::statusPayload());
        $this->api->respond('/dse', 200, self::dseState('not_approved'));

        $this->call('POST', '/connect', ['token' => self::TOKEN]);

        $record = $this->service(DseStore::class)->get();
        static::assertNotNull($record);
        static::assertSame('', $record['html'], 'Die Kopie einer anderen Website darf nicht stehen bleiben.');
    }

    public function testConnectRejectedByTheServiceKeepsTheExistingConnection(): void
    {
        $this->storeToken(self::TOKEN);
        $this->api->respond('/status', 401, ['detail' => ['detail' => 'unauthorized']]);

        [$status, $body] = $this->call('POST', '/connect', ['token' => self::OTHER_TOKEN]);

        static::assertSame(422, $status);
        static::assertSame('unauthorized', $body['state']);
        static::assertSame(self::TOKEN, $this->service(TokenStore::class)->get());
    }

    public function testConnectKeepsATokenTheServiceKnowsButCurrentlyBlocks(): void
    {
        $this->api->respond('/status', 403, ['detail' => ['detail' => 'subscription_inactive']]);

        [$status, $body] = $this->call('POST', '/connect', ['token' => self::TOKEN]);

        static::assertSame(200, $status);
        static::assertSame('subscription_inactive', $body['state']);
        static::assertSame(self::TOKEN, $this->service(TokenStore::class)->get());
        static::assertSame(['/status'], $this->api->routes(), 'Ohne aktives Abo wird keine DSE geholt.');
    }

    public function testStatusPassesServiceDataThrough(): void
    {
        $this->storeToken();
        $this->api->respond('/status', 200, self::statusPayload());

        [$status, $body] = $this->call('GET', '/status');

        static::assertSame(200, $status);
        static::assertSame('ok', $body['state']);
        static::assertSame('critical', $body['status']['status']);
        static::assertSame(2, $body['status']['open_critical']);
        static::assertFalse($body['status']['scan_trigger_allowed']);
        static::assertSame('Tracking ohne Einwilligung', $body['status']['todos'][0]['title']);
        static::assertArrayNotHasKey('id', $body['status']['todos'][0], 'Interne IDs aus dem Dienst werden nicht durchgereicht.');
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>|string, string}>
     */
    public static function serviceFailures(): iterable
    {
        yield 'token revoked' => [401, ['detail' => ['detail' => 'unauthorized']], 'unauthorized'];
        yield 'subscription inactive' => [403, ['detail' => ['detail' => 'subscription_inactive']], 'subscription_inactive'];
        yield 'plan without integrations' => [403, ['detail' => ['detail' => 'feature_not_available']], 'feature_not_available'];
        yield 'rate limited' => [429, [], 'rate_limited'];
        yield 'server error' => [500, 'boom', 'unreachable'];
        yield 'garbage answer' => [200, '<html>nope</html>', 'invalid'];
        yield 'schema violation' => [200, ['status' => 'purple'], 'invalid'];
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('serviceFailures')]
    public function testStatusMapsServiceFailures(int $httpStatus, array|string $body, string $expectedState): void
    {
        $this->storeToken();
        $this->api->respond('/status', $httpStatus, $body, \is_string($body) && $httpStatus === 200 ? 'text/html' : 'application/json');

        [$status, $result] = $this->call('GET', '/status');

        static::assertSame(200, $status);
        static::assertSame($expectedState, $result['state']);
        static::assertNull($result['status']);
    }

    public function testDisconnectRemovesTokenAndCopy(): void
    {
        $this->storeToken();
        $this->service(DseStore::class)->apply(self::dsePayload(), 1000);

        [$status, $body] = $this->call('POST', '/disconnect');

        static::assertSame(200, $status);
        static::assertSame('no_token', $body['state']);
        static::assertNull($body['token_hint']);
        static::assertNull($body['dse']);
        static::assertNull($this->service(TokenStore::class)->get());
        static::assertNull($this->service(DseStore::class)->get());
        static::assertSame([], $this->api->requests());
    }

    public function testSyncDseReportsTheOutcome(): void
    {
        $this->storeToken();
        $this->api->respond('/dse', 200, self::dsePayload());

        [$status, $body] = $this->call('POST', '/dse/sync');

        static::assertSame(200, $status);
        static::assertSame('ok', $body['sync_state']);
        static::assertSame('active', $body['dse']['state']);
        static::assertTrue($body['dse']['has_copy']);
    }

    public function testSyncDseWithoutTokenReportsNoToken(): void
    {
        [, $body] = $this->call('POST', '/dse/sync');

        static::assertSame('no_token', $body['sync_state']);
        static::assertSame([], $this->api->requests());
    }

    public function testReadOnlyUserCanReadButNotChangeTheConnection(): void
    {
        $this->storeToken();
        $this->api->respond('/status', 200, self::statusPayload());
        $this->api->respond('/dse', 200, self::dsePayload());
        $readOnly = ['system_config:read'];

        [$read] = $this->call('GET', '/status', permissions: $readOnly);
        static::assertSame(200, $read);

        $requestsAfterRead = \count($this->api->requests());
        foreach ([['POST', '/connect', ['token' => self::OTHER_TOKEN]], ['POST', '/disconnect', []], ['POST', '/dse/sync', []]] as [$method, $path, $payload]) {
            [$denied] = $this->call($method, $path, $payload, permissions: $readOnly);

            static::assertSame(403, $denied, $method . ' ' . $path);
        }

        static::assertCount($requestsAfterRead, $this->api->requests(), 'Verbotene Aktionen dürfen den Dienst nicht erreichen.');
        static::assertSame(self::TOKEN, $this->service(TokenStore::class)->get());
    }
}
