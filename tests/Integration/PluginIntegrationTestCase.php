<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Service\DseStore;
use Complianty\Connector\Service\TokenStore;
use Complianty\Connector\Tests\Support\FakeComplianty;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Gemeinsame Basis: echter Kernel, Datenbank-Transaktion pro Test, Fake-API ohne Vorgeschichte.
 */
abstract class PluginIntegrationTestCase extends TestCase
{
    use IntegrationTestBehaviour;

    protected const TOKEN = 'clp_site_FakeTokenForTests0123456789abcdefghijk';
    protected const OTHER_TOKEN = 'clp_site_ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ';

    protected FakeComplianty $api;

    protected function setUp(): void
    {
        $this->api = FakeComplianty::instance();
        $this->api->reset();

        // Die Transaktion rollt die Datenbank zurück, nicht den Zwischenspeicher der Systemkonfiguration.
        $config = $this->service(SystemConfigService::class);
        $config->delete(TokenStore::CONFIG_KEY);
        $config->delete(DseStore::CONFIG_KEY);
        $config->reset();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        static::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function statusPayload(string $status = 'critical'): array
    {
        return [
            'status' => $status,
            'open_critical' => 2,
            'open_important' => 1,
            'last_scanned_at' => '2026-08-04T12:18:49.394561Z',
            'scan_state' => 'idle',
            'scan_trigger_allowed' => false,
            'todos' => [
                ['id' => 'a', 'type' => 'X', 'priority' => 'critical', 'title' => 'Tracking ohne Einwilligung', 'text' => 'Text'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function dsePayload(string $html = '<h2>Datenschutz</h2><p>Text</p>'): array
    {
        return ['state' => 'active', 'html' => $html, 'approved_at' => '2026-10-26T08:28:16Z', 'generated_at' => '2026-10-25T08:00:00Z'];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function dseState(string $state): array
    {
        return ['state' => $state, 'html' => null, 'approved_at' => null, 'generated_at' => '2026-10-25T08:00:00Z'];
    }

    protected function storeToken(string $token = self::TOKEN): void
    {
        $this->service(TokenStore::class)->set($token);
    }
}
