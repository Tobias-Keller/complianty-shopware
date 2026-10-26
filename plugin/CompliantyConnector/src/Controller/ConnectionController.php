<?php declare(strict_types=1);

namespace Complianty\Connector\Controller;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;
use Complianty\Connector\Service\DseStore;
use Complianty\Connector\Service\DseSync;
use Complianty\Connector\Service\TokenStore;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => [ApiRouteScope::ID]])]
class ConnectionController extends AbstractController
{
    public function __construct(
        private readonly Client $client,
        private readonly TokenStore $tokens,
        private readonly DseStore $dseStore,
        private readonly DseSync $dseSync,
    ) {
    }

    #[Route(path: '/api/_action/complianty/status', name: 'api.action.complianty.status', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function status(): JsonResponse
    {
        $token = $this->tokens->get();
        if ($token === null) {
            return $this->payload(Result::failure(Result::NO_TOKEN));
        }

        return $this->payload($this->client->status($token));
    }

    #[Route(path: '/api/_action/complianty/connect', name: 'api.action.complianty.connect', defaults: ['_acl' => ['system_config:update', 'system_config:create']], methods: ['POST'])]
    public function connect(Request $request): JsonResponse
    {
        $token = trim((string) $request->request->get('token', ''));
        if (!TokenStore::isWellFormed($token)) {
            return new JsonResponse(['state' => 'malformed'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Erst prüfen, dann speichern: ein abgelehntes Token überschreibt keine funktionierende Verbindung.
        $result = $this->client->status($token);
        if (!$result->tokenAccepted()) {
            return $this->payload($result, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->tokens->set($token);
        // Neues Token, möglicherweise andere Website: alte Kopie verwerfen, frisch holen.
        $this->dseStore->clear();
        if ($result->isOk()) {
            $this->dseSync->sync();
        }

        return $this->payload($result);
    }

    #[Route(path: '/api/_action/complianty/disconnect', name: 'api.action.complianty.disconnect', defaults: ['_acl' => ['system_config:update', 'system_config:create']], methods: ['POST'])]
    public function disconnect(): JsonResponse
    {
        $this->tokens->clear();
        $this->dseStore->clear();

        return $this->payload(Result::failure(Result::NO_TOKEN));
    }

    #[Route(path: '/api/_action/complianty/dse/sync', name: 'api.action.complianty.dse.sync', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function syncDse(): JsonResponse
    {
        $result = $this->dseSync->sync();

        $response = new JsonResponse(['sync_state' => $result->kind, 'dse' => $this->dseMeta()]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route(path: '/api/_action/complianty/scan', name: 'api.action.complianty.scan.start', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function startScan(): JsonResponse
    {
        $token = $this->tokens->get();

        return $this->scanPayload($token === null ? Result::failure(Result::NO_TOKEN) : $this->client->startScan($token));
    }

    #[Route(path: '/api/_action/complianty/scan-status', name: 'api.action.complianty.scan.status', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function scanStatus(): JsonResponse
    {
        $token = $this->tokens->get();

        return $this->scanPayload($token === null ? Result::failure(Result::NO_TOKEN) : $this->client->scanStatus($token));
    }

    /**
     * Ergebnisse rund um den Scan sind immer HTTP 200: "läuft schon" oder "Abkühlzeit" sind erwartbare Zustände,
     * keine Fehler der Admin-API. Die Oberfläche entscheidet anhand von `state`.
     */
    private function scanPayload(Result $result): JsonResponse
    {
        $response = new JsonResponse([
            'state' => $result->kind,
            'code' => $result->code,
            'retry_after' => $result->retryAfter,
            'scan_state' => $result->data['scan_state'] ?? null,
            'last_scanned_at' => $result->data['last_scanned_at'] ?? null,
            'last_scan_error' => $result->data['last_scan_error'] ?? null,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function payload(Result $result, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse([
            'state' => $result->kind,
            'token_hint' => $this->tokens->hint(),
            'status' => $result->data,
            'dse' => $this->dseMeta(),
        ], $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array{state:string,approved_at:?string,checked_at:string,has_copy:bool}|null
     */
    private function dseMeta(): ?array
    {
        $record = $this->dseStore->get();
        if ($record === null) {
            return null;
        }

        return [
            'state' => $record['remote_state'],
            'approved_at' => $record['approved_at'],
            'checked_at' => gmdate('c', $record['checked_at']),
            'has_copy' => $record['html'] !== '',
        ];
    }
}
