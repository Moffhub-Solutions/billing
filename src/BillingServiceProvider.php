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
use Moffhub\Billing\Http\Controllers\WebhookController;
use Moffhub\Billing\Security\FieldEncryptor;
use Moffhub\Billing\Services\BillingService;
use Moffhub\Billing\Services\CouponService;
use Moffhub\Billing\Services\FeatureResolver;
use Moffhub\Billing\Services\UsageService;

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
            ]);
        }

        $this->validateConfig();
        $this->configureRateLimiting();
        $this->registerRoutes();
        $this->registerWebhookRoutes();
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
        $validProviders = ['mpesa', 'paystack', 'flutterwave', 'pesapal', 'manual'];

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

            if (method_exists($user, 'hasFeature')) {
                return $user->hasFeature($featureSlug);
            }

            $billableRelation = config('billing.billable_relation', 'company');

            if (method_exists($user, $billableRelation)) {
                $billable = $user->{$billableRelation};

                return $billable && method_exists($billable, 'hasFeature')
                    ? $billable->hasFeature($featureSlug)
                    : false;
            }

            return false;
        });

        // @plan('professional') ... @endplan
        Blade::if('plan', function (string $planSlug) {
            $user = auth()->user();

            if ($user === null) {
                return false;
            }

            if (method_exists($user, 'onPlan')) {
                return $user->onPlan($planSlug);
            }

            $billableRelation = config('billing.billable_relation', 'company');

            if (method_exists($user, $billableRelation)) {
                $billable = $user->{$billableRelation};

                return $billable && method_exists($billable, 'onPlan')
                    ? $billable->onPlan($planSlug)
                    : false;
            }

            return false;
        });
    }
}
