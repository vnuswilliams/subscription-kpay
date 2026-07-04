---
name: kpay-development
description: Intégration du paiement Mobile Money/Carte KPay pour le package vnuswilliams/laravel-subscription dans une app Laravel (ex. Squarhe). Utiliser ce skill dès qu'il s'agit d'installer, configurer, déboguer ou étendre le paiement d'abonnement via KPay — webhooks, route de retour gateway, middleware kpay.paid, transactions kpay_transactions, events KPayPayment*, ou toute question sur comment facturer une souscription en FCFA/XAF via Mobile Money. Toujours consulter ce skill avant de modifier le package core laravel-subscription pour du paiement, avant d'écrire un listener/webhook KPay, ou avant de créer une migration touchant aux tables subscriptions/kpay_transactions.
---

# Laravel Subscription — Driver KPay

## Principe non négociable

`laravel-subscription` (le core) est **agnostique au paiement**. `subscribeTo()` crée une souscription **active immédiatement**, avec ou sans ce package. `subscription-kpay` est un **satellite** : il écoute des events, appelle l'API KPay, et agit uniquement via l'API publique du core (`suppress()`). Il n'ajoute **jamais** de colonne, d'enum ou de méthode au core.

**Règle à respecter dans toute génération de code** : si une tâche demande d'ajouter un statut de paiement, une colonne, ou une méthode sur le modèle `Subscription` du core → ne pas le faire. Créer plutôt une table/logique côté package satellite (comme `kpay_transactions`), exactement sur le modèle de ce package. Si un autre PSP (Stripe, PayPal...) doit être ajouté un jour, il doit suivre le même schéma : package séparé, table séparée, events séparés.

## Flux complet (à garder en tête pour tout debug)

```
subscribeTo($plan)  →  SubscriptionCreated
                          │
                          ▼
        InitiateKPayPaymentOnSubscriptionCreated (listener)
          - price <= 0            → rien ne se passe
          - price < min_amount    → rejeté avant tout appel API (log only)
          - sinon                 → KPayBillingService::initiatePayment()
                                     crée kpay_transactions (status=pending,
                                     external_id = id de la souscription,
                                     amount = subscription->price, PAS plan->price)
                          │
                          ▼
              Webhook KPay (POST /kpay/webhook, HMAC-SHA256, X-KPAY-Signature)
          - payment.completed  → transaction=success, paid_at, KPayPaymentCompleted
          - payment.failed     → transaction=failed, subscription->suppress(), KPayPaymentFailed
          - payment.cancelled  → transaction=cancelled, subscription->suppress(), KPayPaymentCancelled
```

Points à ne jamais oublier :
- **Seul le webhook fait autorité** pour changer l'état en base. La route de retour (`kpay/return`, mode gateway) ne fait qu'informer l'utilisateur — elle interroge l'API (`GET /api/v1/payments/{id}`) mais ne modifie rien.
- **Échec/annulation = `suppress()`, jamais de suppression en base**. La transaction garde son statut final (`failed` ou `cancelled`) pour l'audit.
- **Idempotence obligatoire** : toute transaction déjà `success`/`failed`/`cancelled` doit ignorer un nouveau webhook (retries KPay).
- **Prix figé (snapshot)** : toujours `subscription->price`, jamais `plan->price`, dans tout code qui initie ou recalcule un paiement.
- **`subscription_id` n'est pas une FK contrainte** (le nom de table `subscriptions` est configurable côté core) — ne jamais ajouter de `foreignId()->constrained()` dessus.

## Installation

```bash
composer require vnuswilliams/subscription-kpay
php artisan vendor:publish --provider="Vnuswilliams\SubscriptionKpay\SubscriptionKpayServiceProvider" --tag=kpay-config
php artisan migrate
```

Ceci crée une seule table : `kpay_transactions`. Aucune migration ne doit toucher aux tables du core.

## Configuration (`.env`)

```env
KPAY_BASE_URL=https://admin.kpay.site
KPAY_API_KEY=
KPAY_SECRET_KEY=
KPAY_WEBHOOK_SECRET=
KPAY_RETURN_SECRET=

KPAY_CURRENCY=XAF          # affichage/stockage uniquement, pas envoyé à l'API
KPAY_DEFAULT_MODE=gateway  # ou ussd
KPAY_MIN_AMOUNT=50         # zone Cameroun

KPAY_RETURN_URL=https://app.test/paiement/retour
KPAY_CANCEL_URL=https://app.test/paiement/annule
```

Autres clés dans `config/kpay.php` : `timeout` (10s), `webhook_route_prefix` (`kpay/webhook`), `return_route_prefix` (`kpay/return`), `payment_pending_route` (`home`), `min_amount` (50).

> KPay ne prend pas de paramètre `currency` à l'initiation — la devise réelle est déduite par KPay selon opérateur/pays. `KPAY_CURRENCY` ne sert qu'au stockage/affichage.

## Préparer un modèle souscripteur

```php
use Vnuswilliams\Subscription\Traits\HasSubscriptions;
use Vnuswilliams\SubscriptionKpay\Traits\HasKPayBilling;

class Company extends Model
{
    use HasSubscriptions;
    use HasKPayBilling; // proxy fin, déjà présent, ne pas dupliquer sa logique ailleurs
}
```

`HasSubscriptions` est un prérequis strict — sans lui, `HasKPayBilling` n'a rien à observer.

## API disponible sur le modèle souscripteur

```php
$company->isCurrentSubscriptionPaid(); // bool — le webhook a confirmé le paiement
$company->kpayTransactions();          // historique complet
$company->latestKPayTransaction();     // dernière transaction de la souscription active
```

Ne jamais requêter directement `KPayTransaction::where('subscription_id', ...)` dans le code applicatif si ces helpers existent déjà — préférer l'API publique du trait.

## Middleware — fenêtre d'attente webhook

Entre `subscribeTo()` (souscription déjà `active` côté core) et la confirmation webhook, il y a une fenêtre. Pour la bloquer :

```php
Route::middleware(['subscribed', 'kpay.paid'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

- JSON → `402 Payment Required`
- Web → redirection vers `config('kpay.payment_pending_route')` + flash `error`

Une fois le paiement `failed`/`cancelled`, le webhook a déjà appelé `suppress()` : le middleware `subscribed` seul suffit alors (il lit `hasAccess()`). `kpay.paid` ne sert **que** pour la fenêtre pré-webhook.

## Webhook — configuration côté KPay

URL à renseigner dans le dashboard KPay :
```
https://app.test/kpay/webhook
```
Route auto-enregistrée par le package, exemptée CSRF. Vérification HMAC-SHA256 (`X-KPAY-Signature`, comparaison à temps constant) avant tout traitement. Signature invalide → `401`, sans effet de bord (pas de log de transaction créée/modifiée).

Si un listener custom doit réagir aux events KPay, toujours passer par les events Laravel du package (voir plus bas), jamais en interceptant la route webhook directement.

## Route de retour (mode `gateway`)

```
https://app.test/paiement/retour?status=COMPLETED&reference=...&externalId=...&ts=...&sig=...
```

Signature distincte du webhook (secret `KPAY_RETURN_SECRET`, format `status|reference|externalId|ts`), anti-replay 10 min sur `ts`.

Comportement fourni :
1. Vérifie signature + fraîcheur (`401` sinon).
2. `GET /api/v1/payments/{id}` pour confirmer l'état réel — l'URL de retour seule n'est jamais fiable.
3. Affiche : succès (déjà confirmé webhook) / attente (`pending`/`processing`, redirection possible vers `payment_pending_route`) / échec/annulation.

## Events Laravel

| Event | Déclenché quand | Payload |
| :--- | :--- | :--- |
| `KPayPaymentInitiated` | Paiement créé côté KPay | `KPayTransaction $transaction` |
| `KPayPaymentCompleted` | Webhook `payment.completed` | `KPayTransaction $transaction` |
| `KPayPaymentFailed` | Webhook `payment.failed` | `KPayTransaction $transaction`, `array $payload` |
| `KPayPaymentCancelled` | Webhook `payment.cancelled` | `KPayTransaction $transaction`, `array $payload` |

```php
use Vnuswilliams\SubscriptionKpay\Events\KPayPaymentFailed;

Event::listen(KPayPaymentFailed::class, function (KPayPaymentFailed $event) {
    Notification::route('mail', $event->payload['customer_email'] ?? null)
        ->notify(new PaymentFailedNotification());
});
```

Ajouter des listeners métier (notifications, logs Squarhe, sync CNPS...) ici — jamais en modifiant les classes du package.

## Table `kpay_transactions`

| Colonne | Type | Notes |
| :--- | :--- | :--- |
| `subscription_id` | `unsignedBigInteger` | pas de FK contrainte (table core configurable) |
| `external_id` | `string`, unique | = id souscription au moment de l'init |
| `kpay_payment_id` | `string`, unique | id retourné par KPay à l'init |
| `kpay_reference` | `string`, nullable | ex. `KPAY-20260514-ABC123` |
| `amount` | `unsignedBigInteger` | copié de `subscription->price` |
| `currency` | `string(3)` | `XAF` par défaut |
| `status` | `string` | `pending`\|`processing`\|`success`\|`failed`\|`cancelled`\|`expired` |
| `raw_payload` | `json`, nullable | réponse brute API/webhook |
| `paid_at` | `timestamp`, nullable | renseigné à la confirmation |

## Pièges fréquents à vérifier avant de générer du code

- Ne jamais utiliser `plan->price` pour un montant de paiement — toujours `subscription->price`.
- Ne jamais supprimer une souscription ou une transaction en cas d'échec — utiliser `suppress()`, garder l'historique.
- Ne jamais traiter un webhook sans vérifier la signature HMAC en premier.
- Ne jamais faire confiance aux query params de la route de retour sans vérifier `sig`/`ts` et sans requêter l'API KPay.
- Ne jamais créer de transaction si `price <= 0` (plan gratuit) ou `price < min_amount`.
- Ne jamais traiter deux fois un webhook sur une transaction déjà dans un statut final (idempotence).
- Ne jamais coupler ce package à un futur PSP (Stripe, PayPal...) — dupliquer le schéma dans un package séparé.

## Prérequis

- PHP `^8.2`, Laravel `^11.0 | ^12.0 | ^13.0`
- `vnuswilliams/laravel-subscription` installé, colonnes `price` présentes sur `plans` et `subscriptions`
- Compte marchand KPay actif