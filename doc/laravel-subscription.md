# Laravel Subscription

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vnuswilliams/laravel-subscription.svg?style=flat-square)](https://packagist.org/packages/vnuswilliams/laravel-subscription)
[![Total Downloads](https://img.shields.io/packagist/dt/vnuswilliams/laravel-subscription.svg?style=flat-square)](https://packagist.org/packages/vnuswilliams/laravel-subscription)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue?style=flat-square)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11%20|%2012%20|%2013-red?style=flat-square)](https://laravel.com)

A robust, fluent, and **fully payment-agnostic** Laravel package for managing subscription plans, lifecycle states (trial, grace period, cancellation), and consumable feature quotas.

This package does not handle any payments. It exclusively manages **subscription business logic**: who has access to what, for how long, and how much they have left. You plug in the payment provider of your choice (Stripe, Paystack, Flutterwave, PayPal…) around it.


This documentation is always release in [french version](readmefr.md).

---

## Table of Contents

1. [Architecture](#architecture)
1. [Installation](#installation)
1. [Configuring Plans in the Database](#configuring-plans-in-the-database)
1. [Preparing the Subscriber Model](#preparing-the-subscriber-model)
1. [Entry Points: Three Ways to Use the Package](#entry-points-three-ways-to-use-the-package)
1. [Managing Subscriptions](#managing-subscriptions)
1. [Features & Quotas](#features--quotas)
1. [Lifecycle & Grace Period](#lifecycle--grace-period)
1. [Route Protection Middleware](#route-protection-middleware)
1. [Laravel Events](#laravel-events)
1. [Artisan Command](#artisan-command)
1. [Full Recipe: Application Service](#full-recipe-application-service)
1. [API Reference](#api-reference)
1. [Tips & Best Practices](#tips--best-practices)

---

## Architecture

The package is built on a strict separation of concerns:

```
SubscriptionManager          ← Public entry point (Facade or injection)
    │
    ├── SubscriptionService  ← Logic: subscribeTo, cancel, switchTo, renew…
    └── FeatureService       ← Logic: canConsume, consume, release, balance…

HasSubscriptions (Trait)     ← Ergonomic proxy on the Eloquent model
```

**Golden rule:** the `HasSubscriptions` trait contains no business logic. It delegates everything to the `SubscriptionManager`. This keeps the logic testable, injectable, and independent of the Eloquent model.

---

## Installation

### 1. Install via Composer

```bash
composer require vnuswilliams/laravel-subscription
```

The `ServiceProvider` and `Facade` are auto-discovered by Laravel. No manual registration needed.

### 2. Publish the configuration and migrations

Use the package install command to publish both the configuration file and the migrations in one step:

```bash
php artisan subscription:install

# Choose this before running migrations if your subscriber model uses UUIDs or ULIDs
# Supported values: id (default), uuid, ulid
SUBSCRIPTIONS_SUBSCRIBER_KEY_TYPE=uuid

# Run migrations
php artisan migrate
```

If `config/subscriptions.php` or any package migration already exists in your application, `subscription:install` deletes the existing file first and regenerates a fresh copy from the package. By default, the `subscriptions.subscriber_key_type` config value is `id`, which creates the classic integer `subscriber_id` column. Set it to `uuid` or `ulid` before running the package migrations when the model using `HasSubscriptions` has a UUID/ULID primary key. The migrations also use `subscriptions.price.precision` and `subscriptions.price.scale` for plan and subscription prices, defaulting to a `decimal(12, 2)` column so values like `100` and `19.99` are both supported.

### 3. (Optional) Generate the application service

The package provides a generator command that scaffolds a ready-to-use `SubscriptionService` tailored to the model that carries the `HasSubscriptions` trait in your application.

Run it and pass your model name via the `--model` option:

```bash
# With a User model
php artisan subscription:generate-service --model=User

# With a Company model
php artisan subscription:generate-service --model=Company

# With a Team model
php artisan subscription:generate-service --model=Team
```

Omit the option to be prompted interactively:

```bash
php artisan subscription:generate-service

# > Which model has the HasSubscriptions trait? (e.g. User, Company, Team)
# > Company
```

This generates `app/Services/SubscriptionService.php` pre-filled with your model. If the file already exists, the command asks for confirmation before overwriting it. See the [Full Recipe](#full-recipe-application-service) section for the generated content and usage examples.

> **Tip:** the model name is case-insensitive — `user`, `User`, and `USER` all produce `User` in the generated file.

---

## Configuring Plans in the Database

The package does not create your plans automatically. You insert them via a seeder, a migration, or your application's admin interface.

Here is the expected structure for a monthly plan with features:

```php
// database/seeders/PlanSeeder.php

use Vnuswilliams\Subscription\Models\Plan;
use Vnuswilliams\Subscription\Enums\FeatureType;
use Vnuswilliams\Subscription\Enums\PeriodicityType;

// Pro Plan — monthly, 7-day grace period
$pro = Plan::create([
    'name'             => 'Pro',
    'slug'             => 'pro',
    'description'      => 'For growing teams.',
    'price'            => 19.99,
    'periodicity_type' => PeriodicityType::Month->value,  // 'month'
    'periodicity'      => 1,
    'trial_days'       => 0,
    'grace_days'       => 7,
    'is_active'        => true,
]);

// Consumable feature: employee quota
$pro->features()->create([
    'slug'    => 'max-employees',
    'name'    => 'Number of employees',
    'type'    => FeatureType::Consumable->value,  // 'consumable'
    'charges' => 25,  // 25 available slots
]);

// Boolean feature: access to the employee portal
$pro->features()->create([
    'slug'    => 'employee-portal',
    'name'    => 'Employee Portal',
    'type'    => FeatureType::Boolean->value,  // 'boolean'
    'charges' => null,  // null = unlimited / no counter
]);

// Free Plan — permanent (no periodicity), 15-day trial
Plan::create([
    'name'             => 'Free',
    'slug'             => 'free',
    'price'            => 0,
    'periodicity_type' => null,   // null = permanent plan, never expires
    'periodicity'      => null,
    'trial_days'       => 15,
    'grace_days'       => 0,
    'is_active'        => true,
]);
```

> **Tip:** centralise all your feature slugs in a `FeatureEnum` in your application. This prevents typos and gives you IDE autocompletion.

```php
// app/Enums/FeatureEnum.php
enum FeatureEnum: string
{
    case MAX_EMPLOYEES    = 'max-employees';
    case EMPLOYEE_PORTAL  = 'employee-portal';
    case DOCUMENTS        = 'documents';
    case ADVANCED_REPORTS = 'advanced-reports';
    case PRIORITY_SUPPORT = 'priority-support';
}
```

---

## Preparing the Subscriber Model

Add the `HasSubscriptions` trait to any Eloquent model that needs to subscribe to a plan: `User`, `Company`, `Team`, `Organization`…

```php
// app/Models/Company.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Vnuswilliams\Subscription\Traits\HasSubscriptions;

class Company extends Model
{
    use HasSubscriptions;
}
```

That's it. The trait automatically exposes the `subscription()` relationship and all of the package's fluent methods directly on your model.

---

## Entry Points: Three Ways to Use the Package

The package exposes three interfaces depending on your context. Choose the one that fits your situation.

### 1. Via the Trait (on the model)

The most fluent syntax for one-off calls directly on the Eloquent instance:

```php
$company->subscribeTo('pro');
$company->hasActiveSubscription();
$company->canConsume('max-employees', 1);
$company->balance('max-employees');
```

Ideal in Observers, Policies, or quick checks inside a Controller.

### 2. Via the Facade (anywhere in the app)

The Laravel-style static syntax, accessible everywhere without injection:

```php
use Vnuswilliams\Subscription\Facades\Subscription;

Subscription::subscribeTo($company, 'pro');
Subscription::cancel($company);
Subscription::canConsume($company, 'max-employees', 1);
Subscription::balance($company, 'max-employees');
```

Ideal in Controllers, Actions, Jobs, or Listeners.

### 3. Via SubscriptionManager injection (in your services)

The recommended approach for complex business logic. Fully testable, with no static dependency:

```php
use Vnuswilliams\Subscription\SubscriptionManager;

class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionManager $subscription,
    ) {}

    public function canAddEmployee(Company $company): bool
    {
        return $this->subscription->hasActiveSubscription($company)
            && $this->subscription->canConsume($company, FeatureEnum::MAX_EMPLOYEES->value, 1);
    }
}
```

> **Tip:** in a dedicated subscription service, always prefer direct injection. The Facade is handy for isolated calls, but makes unit testing harder.

---

## Managing Subscriptions

### Subscribing to a plan

Pass the plan slug (string) or a `Plan` instance directly:

```php
// By slug
$company->subscribeTo('pro');

// By instance
$plan = Plan::where('slug', 'pro')->firstOrFail();
$company->subscribeTo($plan);

// Via the Facade
use Vnuswilliams\Subscription\Facades\Subscription;
Subscription::subscribeTo($company, 'pro');
```

The created subscription stores its own `price`. If you do not pass a custom price, it copies the plan price at subscription time. You can override it for negotiated prices, add-ons, discounts, or prorated amounts:

```php
// Uses the plan price
$company->subscribeTo('pro');

// Stores 24.99 on the subscription, without changing the plan price
$company->subscribeTo('pro', price: 24.99);

// Integer-like prices are supported too
Subscription::subscribeTo($company, 'enterprise', price: 100);
```

If the plan has `trial_days > 0`, the status will automatically be set to `on_trial` and `trial_ends_at` will be calculated. No additional action required.

### Subscribing with a custom expiration

Useful for free plans or fixed-duration promotional offers:

```php
// Free plan with a 15-day trial
$company->subscribeTo('free', expiration: now()->addDays(15));

// Promotional offer: 3 months free
$company->subscribeTo('pro', expiration: now()->addMonths(3));
```

### Switching plans (upgrade / downgrade)

```php
// Immediate switch: the old subscription is removed, the new one starts
$company->switchTo('business');

// Deferred switch: the old subscription runs until its end date
$company->switchTo('starter', immediately: false);

// Via the Facade
Subscription::switchTo($company, 'business');
```

### Cancelling a subscription

Cancellation **does not cut access immediately**. The user retains access until `ends_at`, then the grace period activates if configured. This is the expected behaviour for an end-of-period cancellation.

```php
$company->subscription->cancel();

// Or via the Facade
Subscription::cancel($company);
```

To check whether a subscription is cancelled but still running:

```php
if ($company->subscription->isCanceled()) {
    // The user has cancelled, but still has access until ends_at
    $expiresAt = $company->subscriptionExpiresAt();
}
```

### Revoking access immediately

To cut access without waiting for the end of the period (suspension for non-payment, terms of service violation, etc.):

```php
$company->subscription->suppress();

// Or via the Facade
Subscription::suppress($company);
```

### Renewing a subscription

Starts a full new cycle from now. Useful after a successful payment:

```php
$company->renewSubscription();

// Or via the Facade
Subscription::renew($company);
```

### Checking subscription status

```php
// Is the subscription valid? (active, on trial, or in grace period)
$company->hasActiveSubscription(); // bool

// What is the current plan?
$plan = $company->currentPlan(); // Plan|null
echo $plan->name;  // 'Pro'
echo $plan->slug;  // 'pro'

// When does it expire?
$date = $company->subscriptionExpiresAt(); // Carbon|null

// Direct access to the Subscription model
$sub = $company->subscription;
$sub->isActive();        // bool
$sub->isOnTrial();       // bool
$sub->isOnGracePeriod(); // bool
$sub->isCanceled();      // bool
$sub->isExpired();       // bool
$sub->hasAccess();       // bool — aggregates all valid states
```

---

## Features & Quotas

### Boolean features (yes/no access)

A boolean feature is simply attached to a plan or not. If it is not in the plan's feature list, access is denied.

```php
// Does the company have access to the employee portal?
if ($company->canConsume('employee-portal')) {
    // access granted
}

// Via the Facade
if (Subscription::canConsume($company, 'employee-portal')) {
    // access granted
}
```

> For a boolean feature, the `$amount` parameter is ignored. `canConsume('feature', 0)` and `canConsume('feature', 1)` return the same result.

### Consumable features (quotas)

The standard flow for a quota feature: check → act → consume.

```php
// ✅ Recommended pattern
if ($company->canConsume('max-employees', 1)) {

    // Business action first
    $employee = Employee::create([...]);

    // Consumption afterwards
    $company->consume('max-employees', 1);

} else {
    return back()->with('error', 'Employee quota reached. Please upgrade your plan.');
}
```

> **Important:** always call `canConsume()` before `consume()`. The package does not throw an exception if you consume beyond the quota — that guard is your responsibility.

### Releasing a slot (decrementing consumption)

When you delete a resource, release the corresponding slot:

```php
// Deleting an employee → releases 1 slot
$employee->delete();
$company->release('max-employees', 1);
```

`release()` decrements `used` safely (never below 0). This is more reliable than deleting the last consumption record.

### Inspecting quotas (for dashboards)

```php
// Total slots allocated by the plan
$total = $company->totalCharges('max-employees');  // e.g. 25

// Slots consumed in the current period
$used = $company->usedCharges('max-employees');    // e.g. 17

// Remaining slots (PHP_INT_MAX if unlimited)
$remaining = $company->balance('max-employees');   // e.g. 8
```

Example usage in a Blade view for a progress bar:

```blade
@php
    $total     = $company->totalCharges('max-employees');
    $used      = $company->usedCharges('max-employees');
    $remaining = $company->balance('max-employees');
    $percent   = $total > 0 ? round(($used / $total) * 100) : 0;
@endphp

<div class="quota-bar">
    <div class="quota-bar__fill" style="width: {{ $percent }}%"></div>
</div>
<p>{{ $used }} / {{ $total }} employees — {{ $remaining }} slots remaining</p>
```

---

## Lifecycle & Grace Period

The full lifecycle of a subscription:

```
[on_trial] ──(trial_ends_at passed)──> [active]
[active]   ──(ends_at passed)────────> [on_grace_period] ──(grace_ends_at passed)──> [expired]
[active]   ──(cancel())───────────────> [canceled] (hasAccess() = true until ends_at)
[active]   ──(suppress())─────────────> [expired]  (hasAccess() = false immediately)
```

The `hasAccess()` method is your single source of truth. It returns `true` for the `active`, `on_trial`, `on_grace_period`, and `canceled` (if `ends_at` is in the future) states. It returns `false` for `expired` and suppressed subscriptions.

### Configuring the grace period per plan

The grace period is configured in the plan data (`grace_days` column). No global configuration is required. Each plan can have its own duration:

```php
Plan::create([
    'slug'       => 'pro',
    'grace_days' => 7,   // 7-day grace period after expiration
    // ...
]);

Plan::create([
    'slug'       => 'free',
    'grace_days' => 0,   // No grace period on the free plan
    // ...
]);
```

---

## Route Protection Middleware

The package automatically registers the `subscribed` middleware. Use it in your route files:

```php
// routes/web.php

// Requires any valid subscription
Route::middleware(['auth', 'subscribed'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/employees', [EmployeeController::class, 'index']);
});

// Requires a specific plan (by slug)
Route::middleware(['auth', 'subscribed:business'])->group(function () {
    Route::get('/analytics', [AnalyticsController::class, 'index']);
    Route::get('/support', [SupportController::class, 'index']);
});
```

When access is denied, the middleware returns:

- A **JSON 403** if the request expects JSON (`Accept: application/json`)
- A **redirect** to `home` with an `error` flash message otherwise

To customise this behaviour, extend `CheckSubscription` and rebind it in your `AppServiceProvider`.

---

## Laravel Events

The package dispatches native Laravel events on every lifecycle transition. Register your listeners in `EventServiceProvider` or using Laravel 11+ `#[AsEventListener]` attributes.

| Event                          | Triggered when                        | Typical use case                              |
|--------------------------------|---------------------------------------|-----------------------------------------------|
| `SubscriptionCreated`          | A new subscription is created         | Welcome email, access activation              |
| `SubscriptionCanceled`         | Subscription cancelled (end of period)| Retention email, exit survey                  |
| `SubscriptionEnteredGracePeriod` | Expiration reached, grace activated | Urgent payment reminder email                 |
| `SubscriptionExpired`          | Grace period over, access cut         | Suspension, notification, archiving           |
| `FeatureQuotaReached`          | A feature quota is exhausted          | Upsell notification, admin alert              |

```php
// app/Listeners/SendWelcomeEmail.php

use Vnuswilliams\Subscription\Events\SubscriptionCreated;

class SendWelcomeEmail
{
    public function handle(SubscriptionCreated $event): void
    {
        $subscriber = $event->subscription->subscriber;
        // $subscriber is the Company, User, etc. instance

        Mail::to($subscriber->email)->send(new WelcomeMail($subscriber));
    }
}
```

```php
// app/Listeners/NotifyQuotaExhausted.php

use Vnuswilliams\Subscription\Events\FeatureQuotaReached;

class NotifyQuotaExhausted
{
    public function handle(FeatureQuotaReached $event): void
    {
        $subscriber  = $event->subscription->subscriber;
        $featureSlug = $event->feature->slug;  // e.g. 'max-employees'

        // Send a notification suggesting an upgrade
        $subscriber->notify(new QuotaReachedNotification($featureSlug));
    }
}
```

---

## Artisan Command

The `subscription:check-lifecycle` command iterates over all subscriptions in the database and performs any missing status transitions (active → on_grace_period → expired).

It is useful for users who do not log in often: their subscription will move to grace or expire even without an incoming request, and the relevant events will be dispatched correctly.

```bash
# Manual execution
php artisan subscription:check-lifecycle
```

Schedule it to run daily in `routes/console.php` (Laravel 11+):

```php
// routes/console.php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subscription:check-lifecycle')->daily();
```

Or in `app/Console/Kernel.php` (Laravel 10 and earlier):

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('subscription:check-lifecycle')->daily();
}
```

---

## Full Recipe: Application Service

Generate a ready-to-use `SubscriptionService` by running the generator command with the model that carries the `HasSubscriptions` trait:

```bash
# Replace "Company" with your actual model name
php artisan subscription:generate-service --model=Company
```

This creates `app/Services/SubscriptionService.php` pre-wired to your model. Here is what it contains and how to use it:

```php
// app/Services/SubscriptionService.php

use Vnuswilliams\Subscription\SubscriptionManager;

final class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionManager $subscription,
    ) {}

    public function subscribeTo(Company $company, PlanEnum $planEnum): Subscription
    {
        $plan = $this->subscription->resolvePlan($planEnum->value);

        // App-specific business logic:
        // the FREE plan gets a manual 15-day trial
        if ($planEnum === PlanEnum::FREE) {
            return $this->subscription->subscribeTo($company, $plan, expiration: now()->addDays(15));
        }

        return $this->subscription->subscribeTo($company, $plan);
    }

    public function canAddEmployee(Company $company): bool
    {
        return $this->subscription->hasActiveSubscription($company)
            && $this->subscription->canConsume($company, FeatureEnum::MAX_EMPLOYEES->value, 1);
    }

    public function consumeEmployeeSlot(Company $company): void
    {
        $this->subscription->consume($company, FeatureEnum::MAX_EMPLOYEES->value, 1);
    }

    public function releaseEmployeeSlot(Company $company): void
    {
        $this->subscription->release($company, FeatureEnum::MAX_EMPLOYEES->value, 1);
    }

    public function remainingEmployeeSlots(Company $company): int
    {
        return $this->subscription->balance($company, FeatureEnum::MAX_EMPLOYEES->value);
    }

    public function currentPlan(Company $company): ?PlanEnum
    {
        $plan = $this->subscription->currentPlan($company);

        return $plan !== null ? PlanEnum::tryFrom($plan->slug) : null;
    }
}
```

Usage in a Controller:

```php
// app/Http/Controllers/EmployeeController.php

class EmployeeController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $company = $request->user()->company;

        if (! $this->subscriptionService->canAddEmployee($company)) {
            return back()->with('error', 'Employee quota reached.');
        }

        $employee = Employee::create($request->validated());

        $this->subscriptionService->consumeEmployeeSlot($company);

        return redirect()->route('employees.index')
            ->with('success', 'Employee added successfully.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $company = auth()->user()->company;

        $employee->delete();

        // Release the slot so it can be reused
        $this->subscriptionService->releaseEmployeeSlot($company);

        return redirect()->route('employees.index')
            ->with('success', 'Employee deleted.');
    }
}
```

---

## API Reference

### `HasSubscriptions` Trait

| Method                                         | Return             | Description                                       |
|------------------------------------------------|--------------------|---------------------------------------------------|
| `subscription()`                               | `MorphOne`         | Eloquent relationship to the latest subscription  |
| `subscribeTo($plan, $expiration, $immediately, $price)`| `Subscription`     | Subscribes or switches if an active subscription exists |
| `switchTo($plan, $immediately, $price)`                | `Subscription`     | Switches plan                                     |
| `renewSubscription()`                          | `Subscription`     | Renews from now                                   |
| `hasActiveSubscription()`                      | `bool`             | Is the subscription valid? (active, trial, grace) |
| `currentPlan()`                                | `Plan\|null`       | Current plan                                      |
| `subscriptionExpiresAt()`                      | `Carbon\|null`     | Expiration date                                   |
| `canConsume($slug, $amount)`                   | `bool`             | Quota or boolean access available?                |
| `consume($slug, $amount)`                      | `SubscriptionUsage`| Consumes $amount units                            |
| `release($slug, $amount)`                      | `SubscriptionUsage`| Releases $amount units                            |
| `balance($slug)`                               | `int`              | Remaining balance (PHP_INT_MAX if unlimited)      |
| `totalCharges($slug)`                          | `int`              | Total allocated by the plan                       |
| `usedCharges($slug)`                           | `int`              | Amount consumed                                   |

### `Subscription` Model

| Method              | Return   | Description                              |
|---------------------|----------|------------------------------------------|
| `isActive()`        | `bool`   | Status is active AND ends_at is in the future |
| `isOnTrial()`       | `bool`   | trial_ends_at is in the future           |
| `isOnGracePeriod()` | `bool`   | Within the grace window                  |
| `isCanceled()`      | `bool`   | Cancelled (access may still be available)|
| `isSuppressed()`    | `bool`   | Immediately revoked                      |
| `isExpired()`       | `bool`   | No access remaining                      |
| `hasAccess()`       | `bool`   | Global source of truth                   |
| `cancel()`          | `static` | End-of-period cancellation               |
| `suppress()`        | `static` | Immediate access revocation              |
| `renew()`           | `static` | Renewal from now                         |

### Available Enums

```php
use Vnuswilliams\Subscription\Enums\SubscriptionStatus;
use Vnuswilliams\Subscription\Enums\FeatureType;
use Vnuswilliams\Subscription\Enums\PeriodicityType;

PeriodicityType::Day;    // 'day'
PeriodicityType::Week;   // 'week'
PeriodicityType::Month;  // 'month'
PeriodicityType::Year;   // 'year'

FeatureType::Boolean;    // 'boolean'
FeatureType::Consumable; // 'consumable'

SubscriptionStatus::Active;        // 'active'
SubscriptionStatus::OnTrial;       // 'on_trial'
SubscriptionStatus::OnGracePeriod; // 'on_grace_period'
SubscriptionStatus::Canceled;      // 'canceled'
SubscriptionStatus::Expired;       // 'expired'
```

---

## Tips & Best Practices

**Centralise your feature slugs in an enum.** A typo like `'max-employes'` instead of `'max-employees'` silently returns `false`. `FeatureEnum::MAX_EMPLOYEES->value` never makes that mistake.

**Always check before consuming.** The package does not throw an exception if you call `consume()` when the quota is exhausted. The `canConsume()` guard is your responsibility.

**Use `release()` when deleting resources.** If a user deletes an employee, release the slot. Otherwise the counter stays inflated and the user loses capacity they should get back.

**Do not confuse `cancel()` and `suppress()`.** `cancel()` is a normal cancellation — access is maintained until the end of the paid period. `suppress()` is an administrative or punitive suspension — access is cut immediately.

**Inject `SubscriptionManager` in your services, use the Facade in your controllers.** Services need to be unit-testable — avoid the Facade in classes you test with `pest` or `phpunit`. In a Controller or a Livewire component, the Facade is perfectly appropriate.

**Handle exceptions.** The package throws typed exceptions for error cases:

```php
use Vnuswilliams\Subscription\Exceptions\InvalidPlanException;
use Vnuswilliams\Subscription\Exceptions\SubscriptionNotFoundException;
use Vnuswilliams\Subscription\Exceptions\FeatureNotFoundException;

try {
    Subscription::subscribeTo($company, 'non-existent-plan');
} catch (InvalidPlanException $e) {
    // Plan not found or inactive
    Log::warning($e->getMessage());
}
```

**Schedule the `subscription:check-lifecycle` command without fail.** Without it, an inactive user who makes no requests will never see their subscription transition to `expired` in the database — and `SubscriptionExpired` events will never be dispatched.

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).