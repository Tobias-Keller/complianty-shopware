<?php declare(strict_types=1);

namespace Complianty\Connector\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Hält das Verbindungstoken. Das Token liegt verschlüsselt in der system_config und
 * verlässt den Server nur im Authorization-Header Richtung Complianty, nie Richtung Admin-UI.
 */
class TokenStore
{
    public const CONFIG_KEY = 'CompliantyConnector.config.token';
    public const PATTERN = '/\Aclp_site_[A-Za-z0-9_-]{20,120}\z/';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly string $appSecret,
    ) {
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match(self::PATTERN, $token) === 1;
    }

    public function get(): ?string
    {
        $stored = $this->config->get(self::CONFIG_KEY);
        if (!\is_string($stored) || $stored === '') {
            return null;
        }

        $raw = base64_decode($stored, true);
        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());

        return \is_string($plain) && self::isWellFormed($plain) ? $plain : null;
    }

    public function set(string $token): void
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($token, $nonce, $this->key());

        $this->config->set(self::CONFIG_KEY, base64_encode($nonce . $cipher));
    }

    public function clear(): void
    {
        $this->config->delete(self::CONFIG_KEY);
    }

    /**
     * Kurzform fürs UI, z. B. "clp_site_AbC1…". Genug zum Wiedererkennen, nicht genug zum Benutzen.
     */
    public function hint(): ?string
    {
        $token = $this->get();

        return $token === null ? null : substr($token, 0, 13) . '…';
    }

    private function key(): string
    {
        return sodium_crypto_generichash('complianty-connector:' . $this->appSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
