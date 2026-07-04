<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TODO: la résolution du souscripteur via auth()->user() suppose que le modèle
 * authentifié EST directement le modèle qui porte HasKPayBilling (cas d'un SaaS
 * mono-tenant par utilisateur). Si le souscripteur réel est par exemple
 * $request->user()->currentCompany, fournir `subscriber_resolver` dans config/kpay.php.
 */
class EnsureKPaySubscriptionPaid
{
    public function handle(Request $request, Closure $next): Response
    {
        $subscriber = $this->resolveSubscriber($request);

        if (! $subscriber || $subscriber->isCurrentSubscriptionPaid()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(402, 'Paiement en attente de confirmation.');
        }

        return redirect()
            ->route(config('kpay.payment_pending_route', 'home'))
            ->with('error', 'Votre paiement est en attente de confirmation.');
    }

    private function resolveSubscriber(Request $request): ?object
    {
        $resolver = config('kpay.subscriber_resolver');

        if (is_callable($resolver)) {
            return $resolver($request);
        }

        return $request->user();
    }
}
