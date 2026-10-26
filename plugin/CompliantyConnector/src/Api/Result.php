<?php declare(strict_types=1);

namespace Complianty\Connector\Api;

/**
 * Typisiertes Ergebnis eines Aufrufs. Rohe Server-Texte verlassen den Client nie,
 * `code` ist immer ein Wert aus einer festen Whitelist oder leer.
 */
final class Result
{
    public const OK = 'ok';
    public const NO_TOKEN = 'no_token';
    public const UNAUTHORIZED = 'unauthorized';
    public const SUBSCRIPTION_INACTIVE = 'subscription_inactive';
    public const NO_FEATURE = 'feature_not_available';
    public const FORBIDDEN = 'forbidden';
    public const RATE_LIMITED = 'rate_limited';
    /**
     * Der Dienst hat einen Scan für diese Website vor Kurzem gestartet. `retryAfter` sagt, wann es wieder geht.
     */
    public const COOLDOWN = 'cooldown';
    /**
     * Das Tagesbudget des Tarifs ist aufgebraucht.
     */
    public const BUDGET = 'budget';
    /**
     * Es läuft bereits ein Scan. Kein Fehler, nur ein Hinweis.
     */
    public const CONFLICT = 'conflict';
    /**
     * Der Dienst lehnt die Anfrage inhaltlich ab, etwa weil die Adresse nicht von außen prüfbar ist.
     */
    public const REJECTED = 'rejected';
    public const UNREACHABLE = 'unreachable';
    public const INVALID = 'invalid';

    /**
     * @param array<string,mixed>|null $data
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?array $data = null,
        public readonly string $code = '',
        public readonly int $retryAfter = 0,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function ok(array $data): self
    {
        return new self(self::OK, $data);
    }

    public static function failure(string $kind, string $code = '', int $retryAfter = 0): self
    {
        return new self($kind, null, $code, $retryAfter);
    }

    public function isOk(): bool
    {
        return $this->kind === self::OK;
    }

    /**
     * Das Token wurde vom Dienst erkannt, auch wenn Plan oder Abo gerade nichts erlauben.
     */
    public function tokenAccepted(): bool
    {
        return \in_array($this->kind, [self::OK, self::SUBSCRIPTION_INACTIVE, self::NO_FEATURE, self::FORBIDDEN], true);
    }
}
