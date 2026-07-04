<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Services;

use Illuminate\Support\Facades\Http;
use Vnuswilliams\SubscriptionKpay\Exceptions\KPayException;

/**
 * Wrapper HTTP fin autour de l'API KPay. Ne contient aucune logique métier
 * (pas d'accès à la souscription, pas d'écriture en base) - uniquement les appels réseau.
 *
 * TODO: confirmer avec le dashboard marchand KPay le nom exact des en-têtes d'authentification
 * attendus par l'API (ici on suppose Authorization: Bearer {api_key} + X-Secret-Key).
 */
class KPayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly int $timeout = 10,
    ) {
    }

    /**
     * POST /api/v1/payments/init
     */
    public function initiatePayment(array $payload): array
    {
        $response = $this->client()->post('/api/v1/payments/init', $payload);

        if ($response->failed()) {
            throw new KPayException(
                "Échec de l'initiation du paiement KPay : {$response->status()} — {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * GET /api/v1/payments/{id}
     * Utilisé par la route de retour pour confirmer activement l'état d'un paiement,
     * en complément (jamais en remplacement) du webhook.
     */
    public function getPayment(string $paymentId): array
    {
        $response = $this->client()->get("/api/v1/payments/{$paymentId}");

        if ($response->failed()) {
            throw new KPayException(
                "Échec de la récupération du paiement KPay {$paymentId} : {$response->status()}"
            );
        }

        return $response->json();
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'X-Secret-Key' => $this->secretKey,
                'Accept' => 'application/json',
            ])
            ->asJson();
    }
}
