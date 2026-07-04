<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identifiants API
    |--------------------------------------------------------------------------
    */
    'base_url' => env('KPAY_BASE_URL', 'https://admin.kpay.site'),
    'api_key' => env('KPAY_API_KEY'),
    'secret_key' => env('KPAY_SECRET_KEY'),

    // Secret utilisé pour vérifier la signature HMAC des webhooks (header X-KPAY-Signature).
    'webhook_secret' => env('KPAY_WEBHOOK_SECRET'),

    // Secret utilisé pour vérifier la signature des query params sur l'URL de retour (mode gateway).
    // Distinct du webhook_secret - à confirmer/adapter selon ce que KPay fournit réellement dans le dashboard.
    'return_secret' => env('KPAY_RETURN_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Paiement
    |--------------------------------------------------------------------------
    */

    // Devise stockée/affichée uniquement (n'est pas envoyée à l'API KPay, qui la déduit
    // elle-même de l'opérateur / du pays du provider).
    'currency' => env('KPAY_CURRENCY', 'XAF'),

    // 'gateway' (page hébergée, nécessite return_url) ou 'ussd' (nécessite un numéro de téléphone).
    'default_mode' => env('KPAY_DEFAULT_MODE', 'gateway'),

    // Montant minimum accepté par KPay en zone Cameroun. Toute souscription dont le prix
    // est inférieur à ce montant ne déclenche aucun appel API.
    'min_amount' => (int) env('KPAY_MIN_AMOUNT', 50),

    'return_url' => env('KPAY_RETURN_URL'),
    'cancel_url' => env('KPAY_CANCEL_URL'),

    // Timeout HTTP (secondes) des appels sortants vers l'API KPay.
    'timeout' => (int) env('KPAY_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */
    'webhook_route_prefix' => env('KPAY_WEBHOOK_ROUTE_PREFIX', 'kpay/webhook'),
    'return_route_prefix' => env('KPAY_RETURN_ROUTE_PREFIX', 'kpay/return'),

    // Où rediriger un utilisateur dont le paiement n'est pas (encore) confirmé.
    'payment_pending_route' => env('KPAY_PAYMENT_PENDING_ROUTE', 'home'),

    // Où rediriger un utilisateur dont le paiement vient d'être confirmé (retour gateway).
    'payment_success_route' => env('KPAY_PAYMENT_SUCCESS_ROUTE', 'home'),

    /*
    |--------------------------------------------------------------------------
    | Résolution du souscripteur (middleware kpay.paid)
    |--------------------------------------------------------------------------
    | Callback utilisé par le middleware EnsureKPaySubscriptionPaid pour déterminer
    | quel modèle (Company, Team, User...) porte l'abonnement sur la requête courante.
    | Par défaut on suppose que l'utilisateur authentifié EST le modèle souscripteur
    | (cas d'un SaaS où chaque User a directement HasSubscriptions + HasKPayBilling).
    | À adapter si le souscripteur est par exemple $request->user()->currentCompany.
    */
    'subscriber_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    */
    'table_names' => [
        'kpay_transactions' => 'kpay_transactions',
    ],
];