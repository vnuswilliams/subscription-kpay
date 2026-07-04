<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Events;

use Vnuswilliams\SubscriptionKpay\Models\KPayTransaction;

class KPayPaymentFailed
{
    public function __construct(
        public readonly KPayTransaction $transaction,
        public readonly array $payload,
    ) {
    }
}
