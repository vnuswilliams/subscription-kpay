# Laravel Subscription — KPay Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vnuswilliams/subscription-kpay.svg?style=flat-square)](https://packagist.org/packages/vnuswilliams/subscription-kpay)
[![Total Downloads](https://img.shields.io/packagist/dt/vnuswilliams/subscription-kpay.svg?style=flat-square)](https://packagist.org/packages/vnuswilliams/subscription-kpay)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

Intégration [KPay](https://kpay.site/documentation) (Mobile Money & Cartes) pour [`vnuswilliams/laravel-subscription`](https://github.com/vnuswilliams/laravel-subscription).

Ce package est un **satellite de paiement**, entièrement découplé du cœur du système d'abonnement. Il écoute les événements émis par `laravel-subscription`, déclenche le paiement via l'API KPay, et confirme ou suspend la souscription en fonction du résultat — **sans jamais modifier la structure ou le comportement du package core**.

---

## Sommaire

1. [Philosophie & Architecture](#philosophie--architecture)
2. [Prérequis](#prérequis)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Préparation des Modèles](#préparation-des-modèles)
6. [Flux de paiement (bout en bout)](#flux-de-paiement-bout-en-bout)
7. [Configuration du Webhook KPay](#configuration-du-webhook-kpay)
8. [Flux de retour (mode Gateway)](#flux-de-retour-mode-gateway)
9. [Utilisation de l'API](#utilisation-de-lapi)
10. [Middleware](#middleware)
11. [Événements (Laravel Events)](#événements-laravel-events)
12. [Base de données](#base-de-données)
13. [Notes d'architecture importantes](#notes-darchitecture-importantes)
14. [Licence](#licence)

---

## Philosophie & Architecture

`laravel-subscription` reste **totalement agnostique** au paiement : `subscribeTo()` crée une souscription **active immédiatement**, exactement comme si aucun package de paiement n'était installé. `subscription-kpay` n'ajoute **aucune colonne**, **aucun statut d'enum**, **aucune méthode** au core. Il se contente d'observer et de réagir, en s'appuyant uniquement sur l'API publique déjà exposée par le core (notamment `suppress()`).

```
┌─────────────────────────┐        SubscriptionCreated        ┌──────────────────────────┐
│   laravel-subscription  │ ─────────────────────────────────▶ │    subscription-kpay     │
│   (agnostique, inchangé)│                                     │   (écoute & réagit)      │
└─────────────────────────┘                                     └──────────────────────────┘
                                                                            │
                                                                            ▼
                                                              Initie le paiement KPay
                                                              (montant = subscription->price)
                                                                            │
                            ┌───────────────────────────────────────────────┼───────────────────────────────────────────────┐
                            ▼                                               ▼                                               ▼
                  Webhook KPay : payment.completed              Webhook KPay : payment.failed                 Webhook KPay : payment.cancelled
                  → transaction → success                       → transaction → failed                        → transaction → cancelled
                  → paid_at renseigné                            → subscription->suppress()                    → subscription->suppress()
                  → event KPayPaymentCompleted                   → event KPayPaymentFailed                     → event KPayPaymentCancelled
```

**Principe directeur : c'est à KPay de s'adapter au core, jamais l'inverse.** Si demain un autre moyen de paiement doit être intégré (Stripe, PayPal...), il suivra exactement le même schéma : son propre package, sa propre table, ses propres events — sans jamais toucher à `laravel-subscription`.

---

## Prérequis

- PHP `^8.2`
- Laravel `^11.0 | ^12.0 | ^13.0`
- `vnuswilliams/laravel-subscription` installé et configuré, avec les colonnes `price` présentes sur les tables `plans` et `subscriptions` (voir son [README](https://github.com/vnuswilliams/laravel-subscription))
- Un compte marchand KPay actif ([documentation officielle](https://kpay.site/documentation))

---

## Installation

```bash
composer require vnuswilliams/subscription-kpay
```

Publiez le fichier de configuration :

```bash
php artisan vendor:publish --provider="Vnuswilliams\SubscriptionKpay\SubscriptionKpayServiceProvider" --tag=kpay-config
```

Exécutez les migrations. Le package crée **une seule table**, `kpay_transactions`, sans toucher au schéma du core :

```bash
php artisan migrate
```

---

## Configuration

Ajoutez vos clés KPay dans le fichier `.env` de votre application :

```env
KPAY_BASE_URL=https://admin.kpay.site
KPAY_API_KEY=votre_api_key
KPAY_SECRET_KEY=votre_secret_key
KPAY_WEBHOOK_SECRET=votre_webhook_secret
KPAY_RETURN_SECRET=votre_return_secret

KPAY_CURRENCY=XAF
KPAY_DEFAULT_MODE=gateway
KPAY_MIN_AMOUNT=50

KPAY_RETURN_URL=https://votre-app.test/paiement/retour
KPAY_CANCEL_URL=https://votre-app.test/paiement/annule
```

> **Note sur `KPAY_CURRENCY`** : l'API KPay ne prend pas de paramètre `currency` à l'initiation du paiement — la devise réelle est déduite automatiquement par KPay selon l'opérateur/le pays (`XAF`, `XOF`, `KES`, `ZMW`...). `KPAY_CURRENCY` sert donc uniquement de valeur d'affichage et de stockage par défaut dans `kpay_transactions.currency`, pas à un appel API. La valeur par défaut est `XAF`, cohérente avec la zone FCFA/Cameroun.

Le fichier `config/kpay.php` publié expose tous ces paramètres, plus :

| Clé | Description | Défaut |
| :--- | :--- | :--- |
| `timeout` | Timeout HTTP (secondes) des appels à l'API KPay | `10` |
| `webhook_route_prefix` | Chemin de la route webhook | `kpay/webhook` |
| `return_route_prefix` | Chemin de la route de retour (mode gateway) | `kpay/return` |
| `payment_pending_route` | Route de redirection si paiement non confirmé (contexte web) | `home` |
| `min_amount` | Montant minimum accepté par KPay (zone Cameroun), validé avant tout appel API | `50` |

---

## Préparation des Modèles

Le modèle souscripteur (`Company`, `User`, `Team`, etc.) doit déjà utiliser `HasSubscriptions` du core. Ajoutez simplement `HasKPayBilling` par-dessus :

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Vnuswilliams\Subscription\Traits\HasSubscriptions;
use Vnuswilliams\SubscriptionKpay\Traits\HasKPayBilling;

class Company extends Model
{
    use HasSubscriptions;
    use HasKPayBilling;
}
```

`HasKPayBilling` reste un **proxy fin** — aucune logique métier n'y vit, tout est délégué à `KPayBillingService`, conformément au pattern déjà en place dans le core.

---

## Flux de paiement (bout en bout)

1. **Souscription** — `$company->subscribeTo($plan)` crée la souscription côté core (statut `active`, comportement inchangé) et émet `SubscriptionCreated`.
2. **Déclenchement** — Le listener `InitiateKPayPaymentOnSubscriptionCreated` intercepte l'événement :
   - Si `subscription->price <= 0` (plan gratuit / essai), **rien ne se passe** — aucun appel à KPay.
   - Si `subscription->price` est inférieur à `config('kpay.min_amount')`, l'appel est rejeté avant tout envoi à l'API (log + pas de transaction créée).
   - Sinon, `KPayBillingService::initiatePayment()` appelle l'API KPay avec le montant exact de `subscription->price` (prix figé au moment de la souscription, indépendant du prix courant du plan) et crée une ligne `kpay_transactions` en statut `pending`, avec `external_id` = ID de la souscription.
3. **Paiement** — L'utilisateur finalise le paiement côté KPay (USSD ou page hébergée selon `KPAY_DEFAULT_MODE`).
4. **Webhook** — KPay notifie votre application de manière asynchrone (source d'autorité) :
   - **`payment.completed`** → la transaction passe à `success`, `paid_at` est renseigné, l'événement `KPayPaymentCompleted` est émis. La souscription reste telle quelle côté core.
   - **`payment.failed`** → la transaction passe à `failed`, l'événement `KPayPaymentFailed` est émis, puis `subscription->suppress()` est appelé pour couper l'accès immédiatement.
   - **`payment.cancelled`** → la transaction passe à `cancelled` (statut distinct, pas confondu avec `failed`), l'événement `KPayPaymentCancelled` est émis, puis `subscription->suppress()` est appelé.

> **Pas de suppression en base.** À la différence d'une première version de cette architecture, ni la souscription ni la transaction ne sont supprimées en cas d'échec/annulation : on utilise `suppress()` (déjà fourni par le core) qui coupe l'accès sans détruire les enregistrements. L'historique complet reste disponible dans `kpay_transactions` pour l'audit, le support et le rapprochement comptable.

> **Pas de tâche planifiée.** Ce package ne fait aucune hypothèse sur un scheduler : toute la logique de confirmation/suspension repose exclusivement sur la réception du webhook.

---

## Configuration du Webhook KPay

Dans votre dashboard KPay, configurez l'URL de callback vers :

```
https://votre-app.test/kpay/webhook
```

La route est enregistrée automatiquement par le package et exemptée de la protection CSRF. La signature de chaque requête entrante est vérifiée via HMAC-SHA256 (en-tête `X-KPAY-Signature`, comparaison à temps constant) avant tout traitement — toute signature invalide renvoie `401` sans effet de bord.

---

## Flux de retour (mode Gateway)

En mode `gateway`, après avoir finalisé (ou annulé) le paiement sur la page hébergée KPay, le client est redirigé vers `KPAY_RETURN_URL` avec des paramètres de requête signés :

```
https://votre-app.test/paiement/retour?status=COMPLETED&reference=...&externalId=...&ts=...&sig=...
```

Cette signature est **distincte** de celle du webhook (secret `KPAY_RETURN_SECRET`, format `status|reference|externalId|ts`) et comporte une fenêtre anti-replay de 10 minutes basée sur `ts`.

Comportement de la route `kpay/return` fournie par le package :

1. Vérifie la signature et la fraîcheur du timestamp (`401` si invalide ou expiré).
2. Effectue un `GET /api/v1/payments/{id}` auprès de KPay pour confirmer l'état réel du paiement — **le contenu de l'URL de retour n'est jamais considéré comme fiable à lui seul**, seul le webhook (ou cette vérification active) fait foi.
3. Affiche une page adaptée selon le résultat :
   - Paiement déjà confirmé par le webhook → page de succès.
   - Paiement encore `pending`/`processing` → page d'attente (le webhook n'est pas encore arrivé), redirection possible vers `config('kpay.payment_pending_route')`.
   - Paiement `failed`/`cancelled` → page d'échec/annulation.

Le webhook reste dans tous les cas la seule source qui déclenche les changements d'état en base ; la route de retour ne fait qu'informer l'utilisateur.

---

## Utilisation de l'API

### Vérifier si la souscription active est payée

```php
if ($company->isCurrentSubscriptionPaid()) {
    // Le paiement KPay a été confirmé
}
```

### Consulter l'historique des transactions

```php
$transactions = $company->kpayTransactions();

foreach ($transactions as $transaction) {
    echo $transaction->status->value; // pending | processing | success | failed | cancelled | expired
}
```

### Récupérer la dernière transaction de la souscription active

```php
$latest = $company->latestKPayTransaction();
```

---

## Middleware

Le paiement étant confirmé de façon asynchrone (webhook), il existe une fenêtre entre la création de la souscription (déjà `active` côté core) et la confirmation KPay. Pour bloquer l'accès pendant cette fenêtre, empilez `kpay.paid` par-dessus le middleware `subscribed` du core :

```php
Route::middleware(['subscribed', 'kpay.paid'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});
```

Comportement :
- Requête JSON → `402 Payment Required`
- Requête web → redirection vers `config('kpay.payment_pending_route')` avec un message flash `error`

> Une fois qu'un paiement échoue ou est annulé, la souscription est **suspendue** via `suppress()` par le webhook — le middleware `subscribed` seul suffit alors à couper l'accès (il s'appuie sur `hasAccess()`, qui tient compte de la suspension). `kpay.paid` sert uniquement à la fenêtre d'attente de confirmation, avant tout webhook.

---

## Événements (Laravel Events)

| Événement | Déclenché quand | Payload |
| :--- | :--- | :--- |
| `KPayPaymentInitiated` | Le paiement vient d'être créé côté KPay | `KPayTransaction $transaction` |
| `KPayPaymentCompleted` | Le webhook confirme un paiement réussi | `KPayTransaction $transaction` |
| `KPayPaymentFailed` | Le webhook signale un échec | `KPayTransaction $transaction`, `array $payload` |
| `KPayPaymentCancelled` | Le webhook signale une annulation côté client | `KPayTransaction $transaction`, `array $payload` |

Exemple d'utilisation dans votre `EventServiceProvider` :

```php
use Vnuswilliams\SubscriptionKpay\Events\KPayPaymentFailed;

Event::listen(KPayPaymentFailed::class, function (KPayPaymentFailed $event) {
    Notification::route('mail', $event->payload['customer_email'] ?? null)
        ->notify(new PaymentFailedNotification());
});
```

---

## Base de données

Le package crée une seule table, indépendante du schéma du core :

### `kpay_transactions`

| Colonne | Type | Description |
| :--- | :--- | :--- |
| `subscription_id` | `unsignedBigInteger` | Référence applicative vers `subscriptions` (pas de contrainte FK — voir notes d'architecture) |
| `external_id` | `string`, unique | Identifiant envoyé à KPay lors de l'initiation (= ID de la souscription), utilisé pour le rapprochement et l'idempotence côté init |
| `kpay_payment_id` | `string`, unique | Identifiant du paiement côté KPay (`id` retourné à l'initiation) |
| `kpay_reference` | `string`, nullable | Référence propre à KPay (ex: `KPAY-20260514-ABC123`), utile pour le support et le rapprochement comptable |
| `amount` | `unsignedBigInteger` | Montant, copié depuis `subscription->price` au moment de l'initiation |
| `currency` | `string(3)` | Devise déduite/affichée (`XAF` par défaut) |
| `status` | `string` | `pending` \| `processing` \| `success` \| `failed` \| `cancelled` \| `expired` |
| `raw_payload` | `json`, nullable | Réponse brute de l'API / webhook, pour audit |
| `paid_at` | `timestamp`, nullable | Renseigné à la confirmation du paiement |

---

## Notes d'architecture importantes

- **Aucune dépendance de schéma dure** : `subscription_id` n'est pas une clé étrangère contrainte, car le nom de la table `subscriptions` est configurable côté core (`config('subscriptions.table_names.subscriptions')`). La cohérence est garantie applicativement, pas au niveau base de données.
- **Prix figé (snapshot)** : le montant facturé provient toujours de `subscription->price`, jamais de `plan->price`. Un changement de tarif sur un plan n'affecte donc jamais les souscriptions déjà créées.
- **Échec/annulation = suspension, pas de suppression** : par choix assumé, une souscription dont le paiement échoue ou est annulé est **suspendue** via `suppress()` (API publique du core), et sa transaction conserve son statut final (`failed` ou `cancelled`) — aucune donnée n'est détruite, l'historique complet reste disponible pour l'audit et le support.
- **Statuts distincts pour échec et annulation** : `failed` (paiement refusé/erreur) et `cancelled` (abandon volontaire côté client) sont deux statuts et deux événements séparés, pour ne pas mélanger deux causes différentes dans les rapports/notifications.
- **Idempotence du webhook** : une transaction déjà `success`, `failed` ou `cancelled` ignore tout nouvel appel webhook — évite les doubles traitements en cas de retry côté KPay.
- **Plans gratuits ignorés** : `subscription->price <= 0` court-circuite entièrement l'appel à KPay dès le listener.
- **Montant minimum validé côté application** : `subscription->price` inférieur à `config('kpay.min_amount')` (50 XAF par défaut, zone Cameroun) bloque l'initiation avant tout appel réseau.
- **Retour utilisateur vs webhook** : la route de retour (mode gateway) vérifie sa propre signature (secret et format distincts du webhook) et interroge activement l'API KPay pour informer l'utilisateur — mais seul le webhook fait autorité pour modifier l'état en base.

---

## Licence

Ce package est un logiciel à code source ouvert sous licence [MIT](LICENSE.md).#   s u b s c r i p t i o n - k p a y  
 