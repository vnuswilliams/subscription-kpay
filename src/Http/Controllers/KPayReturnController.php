<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Vnuswilliams\SubscriptionKpay\Enums\KPayTransactionStatus;
use Vnuswilliams\SubscriptionKpay\Models\KPayTransaction;
use Vnuswilliams\SubscriptionKpay\Services\KPayClient;
use Vnuswilliams\SubscriptionKpay\Services\KPaySignatureVerifier;

/**
 * Gère la redirection du client depuis la page hébergée KPay (mode gateway).
 * Le contenu de cette URL n'est JAMAIS considéré comme fiable à lui seul :
 * seul le webhook (ou l'appel actif GET /payments/{id} ci-dessous) fait autorité.
 */
class KPayReturnController extends Controller
{
    public function __construct(
        private readonly KPaySignatureVerifier $verifier,
        private readonly KPayClient $client,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $status = (string) $request->query('status', '');
        $reference = (string) $request->query('reference', '');
        $externalId = (string) $request->query('externalId', '');
        $ts = (string) $request->query('ts', '');
        $signature = $request->query('sig');

        if (! $this->verifier->verifyReturnSignature($status, $reference, $externalId, $ts, $signature)) {
            abort(401, 'Signature de retour KPay invalide ou expirée.');
        }

        $transaction = KPayTransaction::query()->where('external_id', $externalId)->first();

        // Vérification active auprès de KPay en complément du statut déjà en base
        // (le webhook a pu ne pas encore être traité).
        if ($transaction && $transaction->kpay_payment_id) {
            try {
                $this->client->getPayment($transaction->kpay_payment_id);
            } catch (\Throwable) {
                // On ne bloque pas l'affichage sur un échec de vérification active :
                // le webhook reste la source d'autorité pour l'état réel en base.
            }
        }

        return match (true) {
            $transaction?->status === KPayTransactionStatus::Success => redirect()
                ->route(config('kpay.payment_success_route', 'home'))
                ->with('success', 'Votre paiement a été confirmé.'),

            in_array($transaction?->status, [KPayTransactionStatus::Pending, KPayTransactionStatus::Processing, null], true) => redirect()
                ->route(config('kpay.payment_pending_route', 'home'))
                ->with('info', "Votre paiement est en cours de confirmation, merci de patienter quelques instants."),

            default => redirect()
                ->route(config('kpay.payment_pending_route', 'home'))
                ->with('error', 'Le paiement a échoué ou a été annulé.'),
        };
    }
}
