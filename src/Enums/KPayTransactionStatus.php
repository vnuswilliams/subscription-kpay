<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Enums;

enum KPayTransactionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Une transaction terminale ne doit plus être modifiée par un nouveau webhook
     * (idempotence : on ignore tout retraitement d'une transaction déjà close).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Success, self::Failed, self::Cancelled, self::Expired => true,
            self::Pending, self::Processing => false,
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Success;
    }

    /**
     * Mappe le statut brut renvoyé par l'API/le webhook KPay (COMPLETED, FAILED, CANCELLED,
     * PENDING, PROCESSING) vers notre enum interne.
     */
    public static function fromKPayStatus(string $status): self
    {
        return match (strtoupper($status)) {
            'COMPLETED' => self::Success,
            'FAILED' => self::Failed,
            'CANCELLED' => self::Cancelled,
            'PROCESSING' => self::Processing,
            default => self::Pending,
        };
    }
}
