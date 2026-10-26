<?php declare(strict_types=1);

namespace Complianty\Connector\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Lokale Kopie der zuletzt freigegebenen Datenschutzerklärung, bereits gefiltert.
 *
 * Besucher sprechen nie mit Complianty. Die Kopie wird nur durch eine neuere freigegebene
 * Fassung oder den Hinweis zum abgelaufenen Abo ersetzt. Löschen oder Bearbeiten in
 * Complianty entfernt sie nicht.
 */
class DseStore
{
    public const CONFIG_KEY = 'CompliantyConnector.config.dse';
    public const MAX_AGE = 21600;

    public function __construct(private readonly SystemConfigService $config)
    {
    }

    /**
     * @return array{html:string,state:string,remote_state:string,approved_at:?string,generated_at:?string,checked_at:int}|null
     */
    public function get(): ?array
    {
        $raw = $this->config->get(self::CONFIG_KEY);
        if (!\is_array($raw) || !isset($raw['checked_at']) || !\is_int($raw['checked_at'])) {
            return null;
        }

        return [
            'html' => \is_string($raw['html'] ?? null) ? $raw['html'] : '',
            'state' => \is_string($raw['state'] ?? null) ? $raw['state'] : '',
            'remote_state' => \is_string($raw['remote_state'] ?? null) ? $raw['remote_state'] : '',
            'approved_at' => \is_string($raw['approved_at'] ?? null) ? $raw['approved_at'] : null,
            'generated_at' => \is_string($raw['generated_at'] ?? null) ? $raw['generated_at'] : null,
            'checked_at' => $raw['checked_at'],
        ];
    }

    /**
     * @param array{checked_at:int}|null $record
     */
    public function isStale(?array $record, int $now): bool
    {
        return $record === null || ($now - $record['checked_at']) >= self::MAX_AGE;
    }

    /**
     * @param array<string,mixed> $dse Ausgabe von Client::dse()
     *
     * @return bool false, wenn eine aktive Antwort nach dem Filtern leer wäre und deshalb verworfen wurde
     */
    public function apply(array $dse, int $now): bool
    {
        $current = $this->get();
        $remote = (string) $dse['state'];

        $html = $current['html'] ?? '';
        $state = $current['state'] ?? '';
        $approvedAt = $current['approved_at'] ?? null;

        if ($remote === 'active' || $remote === 'lapsed') {
            $filtered = DseSanitizer::sanitize((string) $dse['html']);
            if (!DseSanitizer::hasText($filtered)) {
                return false;
            }
            $html = $filtered;
            $state = $remote;
            $approvedAt = \is_string($dse['approved_at'] ?? null) ? $dse['approved_at'] : null;
        } elseif ($state === 'lapsed') {
            // Gespeichert ist der hinweistext des Dienstes, keine freigegebene Erklärung. Nichts zu behalten.
            $html = '';
            $state = '';
            $approvedAt = null;
        }

        $this->config->set(self::CONFIG_KEY, [
            'html' => $html,
            'state' => $state,
            'remote_state' => $remote,
            'approved_at' => $approvedAt,
            'generated_at' => \is_string($dse['generated_at'] ?? null) ? $dse['generated_at'] : null,
            'checked_at' => $now,
        ]);

        return true;
    }

    public function clear(): void
    {
        $this->config->delete(self::CONFIG_KEY);
    }
}
