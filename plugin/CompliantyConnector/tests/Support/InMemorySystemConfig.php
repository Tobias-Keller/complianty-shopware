<?php declare(strict_types=1);

namespace Complianty\Connector\Tests\Support;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Systemkonfiguration im Speicher. Anders als der StaticSystemConfigService des Kerns kann sie löschen,
 * ohne die Datenbank anzufassen.
 */
final class InMemorySystemConfig extends SystemConfigService
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    // Wie StaticSystemConfigService im Kern: der Elternkonstruktor braucht Datenbank und Cache, die ein Unit-Test nicht hat.
    // @phpstan-ignore constructor.missingParentCall
    public function __construct()
    {
    }

    public function get(string $key, ?string $salesChannelId = null): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, $value, ?string $salesChannelId = null): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key, ?string $salesChannelId = null): void
    {
        unset($this->values[$key]);
    }

    /**
     * Roh-Wert, so wie er in der Datenbank stünde.
     */
    public function raw(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }
}
