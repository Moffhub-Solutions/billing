<?php

declare(strict_types=1);

namespace Moffhub\Billing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Moffhub\Billing\Contracts\FeatureResolverInterface;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Contracts\TaxCalculatorInterface;
use Moffhub\Billing\Http\Controllers\UssdController;
use Moffhub\Billing\Http\Controllers\WebhookController;
use Moffhub\Billing\Security\FieldEncryptor;
use Moffhub\Billing\Services\BillingService;
use Moffhub\Billing\Services\CouponService;
use Moffhub\Billing\Services\FeatureResolver;
use Moffhub\Billing\Services\InvoiceService;
use Moffhub\Billing\Services\KenyanTaxCalculator;
use Moffhub\Billing\Services\UsageService;
use Moffhub\Billing\Services\UssdMenuBuilder;
use Moffhub\Billing\Services\UssdSessionManager;

class BillingServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/billing.php', 'billing');

        $this->app->singleton(PaymentManager::class, fn ($app): PaymentManager => new PaymentManager($app));

        $this->app->singleton(FeatureResolverInterface::class, FeatureResolver::class);

        $this->app->singleton(FeatureResolver::class);

        $this->app->singleton(UsageService::class);

        $this->app->singleton(CouponService::class);

        $this->app->singleton(FieldEncryptor::class);

        $this->app->singleton(BillingService::class, fn ($app): BillingService => new BillingService(
            $app->make(FeatureResolver::class),
            $app->make(UsageService::class),
        ));

        $this->app->singleton('billing', fn ($app) => $app->make(BillingService::class));

        // Bind the default payment provider
        $this->app->bind(PaymentProviderInterface::class, fn ($app) => $app->make(PaymentManager::class)->driver());

        // Bind tax calculator — use config override or fall back to KenyanTaxCalculator
        $this->app->singleton(TaxCalculatorInterface::class, function ($app) {
            $customClass = config('billing.tax.calculator');

            if ($customClass && class_exists($customClass)) {
                return $app->make($customClass);
            }

            return $app->make(KenyanTaxCalculator::class);
        });

        $this->app->singleton(InvoiceService::class);

        $this->app->singleton(UssdSessionManager::class);

        $this->app->singleton(UssdMenuBuilder::class, fn ($app): UssdMenuBuilder => new UssdMenuBuilder(
            $app->make(UssdSessionManager::class),
            $app->make(PaymentManager::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/Config/billing.php' => config_path('billing.php'),
        ], 'billing-config');

        $this->publishesMigrations([
            __DIR__.'/Database/Migrations' => database_path('migrations'),
        ], 'billing-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\SyncPlansCommand::class,
                Console\Commands\BillingHealthCommand::class,
                Console\Commands\ProcessRenewalsCommand::class,
                Console\Commands\ProcessInvoicesCommand::class,
            ]);
        }

        $this->validateConfig();
        $this->configureRateLimiting();
        $this->registerRoutes();
        $this->registerWebhookRoutes();
        $this->registerUssdRoutes();
        $this->registerBladeDirectives();
    }

    /**
     * Register billing API routes.
     */
    protected function registerRoutes(): void
    {
        if (! config('billing.routes.enabled', true)) {
            return;
        }

        Route::group($this->routeConfiguration(), function (): void {
            $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        });
    }

    /**
     * Get the route group configuration.
     */
    protected function routeConfiguration(): array
    {
        $middleware = config('billing.routes.middleware', ['api']);
        $rateLimit = config('billing.routes.rate_limit');

        if ($rateLimit) {
            $middleware[] = 'throttle:billing';
        }

        return [
            'prefix' => config('billing.routes.prefix', 'api/billing'),
            'middleware' => $middleware,
        ];
    }

    /**
     * Register payment provider webhook routes.
     */
    protected function registerWebhookRoutes(): void
    {
        if (! config('billing.webhooks.enabled', true)) {
            return;
        }

        $prefix = config('billing.webhooks.prefix', 'billing/webhooks');
        $rateLimit = (int) config('billing.webhooks.rate_limit', 60);

        RateLimiter::for('billing-webhooks', fn (Request $request) => Limit::perMinute($rateLimit)->by($request->ip()));

        Route::prefix($prefix)
            ->withoutMiddleware(['auth', 'auth:sanctum', 'auth:api'])
            ->middleware([
                'throttle:billing-webhooks',
            ])
            ->group(function (): void {
                Route::post('/mpesa', [WebhookController::class, 'mpesa'])->name('billing.webhooks.mpesa');
                Route::post('/paystack', [WebhookController::class, 'paystack'])->name('billing.webhooks.paystack');
                Route::post('/flutterwave', [WebhookController::class, 'flutterwave'])->name('billing.webhooks.flutterwave');
                Route::post('/pesapal', [WebhookController::class, 'pesapal'])->name('billing.webhooks.pesapal');
                Route::post('/airtel', [WebhookController::class, 'airtel'])->name('billing.webhooks.airtel');
                Route::post('/kcb', [WebhookController::class, 'kcb'])->name('billing.webhooks.kcb');
                Route::post('/jenga', [WebhookController::class, 'jenga'])->name('billing.webhooks.jenga');
                Route::post('/coopbank', [WebhookController::class, 'coopbank'])->name('billing.webhooks.coopbank');
                Route::post('/stanbic', [WebhookController::class, 'stanbic'])->name('billing.webhooks.stanbic');
                Route::post('/ncba', [WebhookController::class, 'ncba'])->name('billing.webhooks.ncba');
                Route::post('/intasend', [WebhookController::class, 'intasend'])->name('billing.webhooks.intasend');
            });
    }

    /**
     * Register USSD callback route.
     */
    protected function registerUssdRoutes(): void
    {
        if (! config('billing.ussd.enabled', false)) {
            return;
        }

        Route::prefix('billing/ussd')
            ->withoutMiddleware(['auth', 'auth:sanctum', 'auth:api'])
            ->group(function (): void {
                Route::post('/callback', [UssdController::class, 'handle'])->name('billing.ussd.callback');
            });
    }

    /**
     * Configure rate limiting for billing routes.
     */
    protected function configureRateLimiting(): void
    {
        $maxAttempts = (int) config('billing.routes.rate_limit', 60);

        if ($maxAttempts <= 0) {
            return;
        }

        RateLimiter::for('billing', fn (Request $request) => Limit::perMinute($maxAttempts)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }

    /**
     * Validate billing configuration on boot.
     */
    protected function validateConfig(): void
    {
        $provider = config('billing.default_provider');
        $validProviders = ['mpesa', 'paystack', 'flutterwave', 'pesapal', 'airtel', 'kcb', 'jenga', 'coopbank', 'stanbic', 'ncba', 'intasend', 'manual'];

        if ($provider && ! in_array($provider, $validProviders, true)) {
            Log::warning("Billing: Unrecognized default provider '{$provider}'. Valid providers: ".implode(', ', $validProviders));
        }

        $currency = config('billing.currency');

        if ($currency && strlen((string) $currency) !== 3) {
            Log::warning("Billing: Currency '{$currency}' should be a 3-letter ISO 4217 code (e.g., KES, USD).");
        }
    }

    /**
     * Register Blade directives for feature gating.
     */
    protected function registerBladeDirectives(): void
    {
        // @feature('ocr_scanning') ... @endfeature
        Blade::if('feature', function (string $featureSlug) {
            $user = auth()->user();

            if ($user === null) {
                return false;
            }

            $billable = $user;

            if (! method_exists($user, 'hasFeature')) {
                $billableRelation = config('billing.billable_relation', 'company');

                $billable = method_exists($user, $billableRelation)
                    ? $user->{$billableRelation}
                    : null;
            }

            if ($billable === null) {
                return false;
            }

            // Admin bypass
            if (method_exists($billable, 'isBillingAdmin') && $billable->isBillingAdmin()) {
                return true;
            }

            return method_exists($billable, 'hasFeature') && $billable->hasFeature($featureSlug);
        });

        // @plan('professional') ... @endplan
        Blade::if('plan', function (string $planSlug) {
            $user = auth()->user();

            if ($user === null) {
                return false;
            }

            $billable = $user;

            if (! method_exists($user, 'onPlan')) {
                $billableRelation = config('billing.billable_relation', 'company');

                $billable = method_exists($user, $billableRelation)
                    ? $user->{$billableRelation}
                    : null;
            }

            if ($billable === null) {
                return false;
            }

            // Admin bypass
            if (method_exists($billable, 'isBillingAdmin') && $billable->isBillingAdmin()) {
                return true;
            }

            return method_exists($billable, 'onPlan') && $billable->onPlan($planSlug);
        });
    }
}
