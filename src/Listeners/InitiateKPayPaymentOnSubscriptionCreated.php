<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Listeners;

use Vnuswilliams\Subscription\Events\SubscriptionCreated;
use Vnuswilliams\SubscriptionKpay\Services\KPayBillingService;

/**
 * TODO: confirmer que l'event du core s'appelle bien `SubscriptionCreated` et expose
 * une propriété publique `subscription` (namespace supposé : Vnuswilliams\Subscription\Events).
 */
class InitiateKPayPaymentOnSubscriptionCreated
{
    public function __construct(
        private readonly KPayBillingService $billingService,
    ) {
    }

    public function handle(SubscriptionCreated $event): void
    {
        $this->billingService->initiatePayment($event->subscription);
    }
}
