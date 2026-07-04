<?php

use Illuminate\Support\Facades\Route;
use Vnuswilliams\SubscriptionKpay\Http\Controllers\KPayReturnController;
use Vnuswilliams\SubscriptionKpay\Http\Controllers\KPayWebhookController;
use Vnuswilliams\SubscriptionKpay\Http\Middleware\VerifyKPayWebhookSignature;

/*
|--------------------------------------------------------------------------
| Routes KPay
|--------------------------------------------------------------------------
|
| Volontairement enregistrées SANS le groupe middleware "web" (donc sans CSRF)
| puisqu'il s'agit d'un webhook et d'une redirection externe signés par HMAC.
| Si votre application applique un middleware CSRF global (bootstrap/app.php,
| Laravel 11+), ajoutez ces deux URIs à ses exceptions.
|
*/

Route::post(
    config('kpay.webhook_route_prefix', 'kpay/webhook'),
    KPayWebhookController::class
)->middleware(VerifyKPayWebhookSignature::class)->name('kpay.webhook');

Route::get(
    config('kpay.return_route_prefix', 'kpay/return'),
    KPayReturnController::class
)->name('kpay.return');
