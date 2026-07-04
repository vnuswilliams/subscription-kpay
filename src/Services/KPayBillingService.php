<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Vnuswilliams\SubscriptionKpay\Enums\KPayTransactionStatus;
use Vnuswilliams\SubscriptionKpay\Events\KPayPaymentCancelled;
use Vnuswilliams\SubscriptionKpay\Events\KPayPaymentCompleted;
use Vnuswilliams\SubscriptionKpay\Events\KPayPaymentFailed;
use Vnuswilliams\SubscriptionKpay\Events\KPayPaymentInitiated;
use Vnuswilliams\SubscriptionKpay\Exceptions\KPayException;
use Vnuswilliams\SubscriptionKpay\Models\KPayTransaction;

/**
 * Toute la logique métier du package vit ici (services single-responsibility,
 * conformément au pattern déjà en place dans laravel-subscription).
 *
 * TODO: `$subscription->price` et le namespace du modèle Subscription du core sont
 * supposés ici tels que confirmés — adapter si le core renomme la colonne ou le modèle.
 */
class KPayBillingService
{
    public function __construct(
        private readonly KPayClient $client,
    ) {
    }

    /**
     * Déclenché par le listener sur SubscriptionCreated.
     */
    public function initiatePayment(object $subscription): ?KPayTransaction
    {
        $amount = (int) $subscription->price;

        if ($amount <= 0) {
            // Plan gratuit / essai : aucun appel KPay.
            return null;
        }

        $minAmount = (int) config('kpay.min_amount', 50);

        if ($amount < $minAmount) {
            Log::warning("KPay: souscription #{$subscription->id} ignorée, montant {$amount} inférieur au minimum {$minAmount}.");

            return null;
        }

        $externalId = (string) $subscription->id;

        $response = $this->client->initiatePayment([
            'externalId' => $externalId,
            'amount' => $amount,
            'mode' => config('kpay.default_mode', 'gateway'),
            'returnUrl' => config('kpay.return_url'),
            'cancelUrl' => config('kpay.cancel_url'),
        ]);

        $transaction = KPayTransaction::create([
            'subscription_id' => $subscription->id,
            'external_id' => $externalId,
            'kpay_payment_id' => $response['id'] ?? null,
            'kpay_reference' => $response['reference'] ?? null,
            'amount' => $amount,
            'currency' => config('kpay.currency', 'XAF'),
            'status' => KPayTransactionStatus::Pending,
            'raw_payload' => $response,
        ]);

        Event::dispatch(new KPayPaymentInitiated($transaction));

        return $transaction;
    }

    /**
     * Traite un payload de webhook déjà vérifié (signature validée en amont par le middleware).
     */
    public function handleWebhookPayload(array $payload): void
    {
        $paymentId = $payload['id'] ?? $payload['paymentId'] ?? null;
        $externalId = $payload['externalId'] ?? null;
        $rawStatus = $payload['status'] ?? null;

        if (blank($paymentId) && blank($externalId)) {
            throw new KPayException('Webhook KPay reçu sans id ni externalId exploitable.');
        }

        $transaction = KPayTransaction::query()
            ->when($paymentId, fn ($query) => $query->orWhere('kpay_payment_id', $paymentId))
            ->when($externalId, fn ($query) => $query->orWhere('external_id', $externalId))
            ->first();

        if (! $transaction) {
            Log::warning("KPay webhook: aucune transaction trouvée pour paymentId={$paymentId} externalId={$externalId}");

            return;
        }

        // Idempotence : une transaction déjà terminale ignore tout nouvel appel webhook.
        if ($transaction->status->isTerminal()) {
            return;
        }

        $newStatus = KPayTransactionStatus::fromKPayStatus((string) $rawStatus);

        $transaction->update([
            'kpay_payment_id' => $transaction->kpay_payment_id ?? $paymentId,
            'kpay_reference' => $payload['reference'] ?? $transaction->kpay_reference,
            'status' => $newStatus,
            'raw_payload' => $payload,
            'paid_at' => $newStatus === KPayTransactionStatus::Success ? now() : null,
        ]);

        match ($newStatus) {
            KPayTransactionStatus::Success => Event::dispatch(new KPayPaymentCompleted($transaction)),
            KPayTransactionStatus::Failed => $this->handleUnsuccessful($transaction, $payload, failed: true),
            KPayTransactionStatus::Cancelled => $this->handleUnsuccessful($transaction, $payload, failed: false),
            default => null, // pending / processing : rien de plus à faire, on attend le webhook suivant
        };
    }

    /**
     * Échec et annulation partagent le même traitement (suspension de la souscription),
     * mais restent deux statuts et deux events distincts pour ne pas mélanger les causes.
     */
    private function handleUnsuccessful(KPayTransaction $transaction, array $payload, bool $failed): void
    {
        $subscription = $transaction->subscription();

        if ($subscription && method_exists($subscription, 'suppress')) {
            $subscription->suppress();
        } else {
            Log::warning("KPay: impossible de suspendre la souscription #{$transaction->subscription_id} (introuvable ou méthode suppress() absente).");
        }

        Event::dispatch(
            $failed
                ? new KPayPaymentFailed($transaction, $payload)
                : new KPayPaymentCancelled($transaction, $payload)
        );
    }
}
