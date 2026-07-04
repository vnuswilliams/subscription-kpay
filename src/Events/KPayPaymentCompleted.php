<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Events;

use Vnuswilliams\SubscriptionKpay\Models\KPayTransaction;

class KPayPaymentCompleted
{
    public function __construct(
        public readonly KPayTransaction $transaction,
    ) {
    }
}
