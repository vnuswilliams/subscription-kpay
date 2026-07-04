<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Services;

/**
 * Deux schémas de signature distincts chez KPay :
 * - Webhook : HMAC-SHA256 du corps brut de la requête, secret `webhook_secret`,
 *   transmis dans l'en-tête X-KPAY-Signature.
 * - Retour (mode gateway) : HMAC-SHA256 de "status|reference|externalId|ts",
 *   secret `return_secret`, transmis en query param `sig`, avec fenêtre anti-replay.
 */
class KPaySignatureVerifier
{
    private const RETURN_SIGNATURE_MAX_AGE_SECONDS = 600; // 10 minutes

    public function __construct(
        private readonly string $webhookSecret,
        private readonly string $returnSecret,
    ) {
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        if (blank($signatureHeader) || blank($this->webhookSecret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        return hash_equals($expected, $signatureHeader);
    }

    public function verifyReturnSignature(string $status, string $reference, string $externalId, string $ts, ?string $signature): bool
    {
        if (blank($signature) || blank($this->returnSecret)) {
            return false;
        }

        if (! $this->isTimestampFresh($ts)) {
            return false;
        }

        $stringToSign = "{$status}|{$reference}|{$externalId}|{$ts}";
        $expected = hash_hmac('sha256', $stringToSign, $this->returnSecret);

        return hash_equals($expected, $signature);
    }

    private function isTimestampFresh(string $ts): bool
    {
        if (! ctype_digit($ts)) {
            return false;
        }

        return abs(time() - (int) $ts) <= self::RETURN_SIGNATURE_MAX_AGE_SECONDS;
    }
}
