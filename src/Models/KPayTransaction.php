<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Models;

use Illuminate\Database\Eloquent\Model;
use Vnuswilliams\SubscriptionKpay\Enums\KPayTransactionStatus;

class KPayTransaction extends Model
{
    protected $fillable = [
        'subscription_id',
        'external_id',
        'kpay_payment_id',
        'kpay_reference',
        'amount',
        'currency',
        'status',
        'raw_payload',
        'paid_at',
    ];

    protected $casts = [
        'status' => KPayTransactionStatus::class,
        'raw_payload' => 'array',
        'paid_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('kpay.table_names.kpay_transactions', 'kpay_transactions');
    }

    /**
     * TODO: adapter le namespace ci-dessous si le modèle Subscription du core
     * vit à un autre endroit (Vnuswilliams\Subscription\Models\Subscription supposé ici).
     * On ne définit pas de vraie relation Eloquent belongsTo() pour rester découplé
     * du nom de table configurable du core - simple lookup applicatif.
     */
    public function subscription(): ?object
    {
        $subscriptionModel = config('subscriptions.model', \Vnuswilliams\Subscription\Models\Subscription::class);

        return $subscriptionModel::find($this->subscription_id);
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }
}
