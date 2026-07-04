<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Traits;

use Illuminate\Support\Collection;
use Vnuswilliams\SubscriptionKpay\Enums\KPayTransactionStatus;
use Vnuswilliams\SubscriptionKpay\Models\KPayTransaction;

/**
 * À utiliser en complément de Vnuswilliams\Subscription\Traits\HasSubscriptions
 * sur le modèle souscripteur (Company, Team, User...).
 *
 * Reste un proxy fin : toute la logique métier vit dans KPayBillingService.
 *
 * TODO: `$this->subscription()` suppose que le core expose un accesseur retournant
 * la souscription active du modèle courant. Adapter le nom de la méthode si le
 * core utilise plutôt `currentSubscription()`, `activeSubscription()`, etc.
 */
trait HasKPayBilling
{
    public function kpayTransactions(): Collection
    {
        $subscriptionIds = $this->subscriptions()->pluck('id');

        return KPayTransaction::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->latest()
            ->get();
    }

    public function latestKPayTransaction(): ?KPayTransaction
    {
        $subscription = $this->subscription();

        if (! $subscription) {
            return null;
        }

        return KPayTransaction::query()
            ->where('subscription_id', $subscription->id)
            ->latest()
            ->first();
    }

    public function isCurrentSubscriptionPaid(): bool
    {
        $latest = $this->latestKPayTransaction();

        return $latest !== null && $latest->status === KPayTransactionStatus::Success;
    }
}
