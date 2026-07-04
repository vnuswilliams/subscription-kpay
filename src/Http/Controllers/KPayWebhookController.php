<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Vnuswilliams\SubscriptionKpay\Services\KPayBillingService;

class KPayWebhookController extends Controller
{
    public function __invoke(Request $request, KPayBillingService $billingService): JsonResponse
    {
        // La signature a déjà été vérifiée par le middleware VerifyKPayWebhookSignature.
        $billingService->handleWebhookPayload($request->all());

        return response()->json(['received' => true]);
    }
}
