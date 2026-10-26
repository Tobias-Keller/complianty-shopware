<?php declare(strict_types=1);

namespace Complianty\Connector\Service;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;

/**
 * Holt die Datenschutzerklärung und legt sie lokal ab.
 */
class DseSync
{
    private const FIRST_FETCH_TIMEOUT = 3;

    public function __construct(
        private readonly Client $client,
        private readonly TokenStore $tokens,
        private readonly DseStore $store,
    ) {
    }

    /**
     * Nutzerausgelöst oder per Zeitplan: volles Timeout.
     */
    public function sync(): Result
    {
        return $this->run(Client::TIMEOUT);
    }

    /**
     * Beim ersten Seitenaufruf ohne Kopie: kurzes Timeout, damit die Seite nicht hängt.
     */
    public function firstFetch(): void
    {
        $this->run(self::FIRST_FETCH_TIMEOUT);
    }

    private function run(int $timeout): Result
    {
        $token = $this->tokens->get();
        if ($token === null) {
            return Result::failure(Result::NO_TOKEN);
        }

        $result = $this->client->dse($token, $timeout);
        if ($result->isOk() && $result->data !== null) {
            if (!$this->store->apply($result->data, time())) {
                return Result::failure(Result::INVALID);
            }
        }

        return $result;
    }
}
