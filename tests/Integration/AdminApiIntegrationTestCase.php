<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Integration;

use Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;

/**
 * Basis für Tests der Admin-API-Routen des Plugins.
 */
abstract class AdminApiIntegrationTestCase extends PluginIntegrationTestCase
{
    use AdminApiTestBehaviour;

    protected const BASE = '/api/_action/complianty';

    /**
     * @param array<string, mixed>|null $body
     * @param list<string>|null $permissions
     *
     * @return array{int, array<string, mixed>, string}
     */
    protected function call(string $method, string $path, ?array $body = null, bool $authorized = true, ?array $permissions = null): array
    {
        $browser = $this->getBrowser($authorized, [], $permissions);
        $browser->request($method, self::BASE . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body === null ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        $raw = (string) $browser->getResponse()->getContent();
        $decoded = json_decode($raw, true);

        return [$browser->getResponse()->getStatusCode(), \is_array($decoded) ? $decoded : [], $raw];
    }
}
