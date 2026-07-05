<?php

declare(strict_types=1);

namespace Vnuswilliams\SubscriptionKpay;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Vnuswilliams\Subscription\Events\SubscriptionCreated;
use Vnuswilliams\SubscriptionKpay\Http\Middleware\EnsureKPaySubscriptionPaid;
use Vnuswilliams\SubscriptionKpay\Listeners\InitiateKPayPaymentOnSubscriptionCreated;
use Vnuswilliams\SubscriptionKpay\Services\KPayClient;
use Vnuswilliams\SubscriptionKpay\Services\KPaySignatureVerifier;
use Vnuswilliams\SubscriptionKpay\Console\PublishKpayConfig;

class SubscriptionKpayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kpay.php', 'kpay');

        $this->app->singleton(KPayClient::class, fn () => new KPayClient(
            baseUrl: (string) config('kpay.base_url'),
            apiKey: (string) config('kpay.api_key'),
            secretKey: (string) config('kpay.secret_key'),
            timeout: (int) config('kpay.timeout', 10),
        ));

        $this->app->singleton(KPaySignatureVerifier::class, fn () => new KPaySignatureVerifier(
            webhookSecret: (string) config('kpay.webhook_secret'),
            returnSecret: (string) config('kpay.return_secret'),
        ));

    }


    public function boot(): void
    {
        $this->publishConfig();
        $this->pusblishMigrations();
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerEventListeners();
        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishKpayConfig::class,
            ]);
        }
    }

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/kpay.php' => config_path('kpay.php'),
        ], 'kpay-config');
    }

    private function pusblishMigrations(): void
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'kpay-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

   

    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/kpay.php');
    }

    private function registerMiddleware(): void
    {
        $this->app['router']->aliasMiddleware('kpay.paid', EnsureKPaySubscriptionPaid::class);
    }

    /**
     * TODO: confirmer le namespace exact de SubscriptionCreated côté core
     * (voir aussi la note dans InitiateKPayPaymentOnSubscriptionCreated).
     */
    private function registerEventListeners(): void
    {
        Event::listen(SubscriptionCreated::class, InitiateKPayPaymentOnSubscriptionCreated::class);
    }
}
