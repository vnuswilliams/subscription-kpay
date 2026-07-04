<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vnuswilliams\SubscriptionKpay\Services\KPaySignatureVerifier;

class VerifyKPayWebhookSignature
{
    public function __construct(
        private readonly KPaySignatureVerifier $verifier,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-KPAY-Signature');

        if (! $this->verifier->verifyWebhookSignature($request->getContent(), $signature)) {
            abort(401, 'Signature KPay invalide.');
        }

        return $next($request);
    }
}
