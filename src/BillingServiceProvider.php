<?php

declare(strict_types=1);

namespace Moffhub\Billing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
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
use Moffhub\Billing\Http\Middleware\CheckFeatureAccess;
use Moffhub\Billing\Http\Middleware\CheckPlanAccess;
use Moffhub\Billing\Http\Middleware\CheckUsageLimit;
use Moffhub\Billing\Http\Middleware\RequireSubscription;
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

            if (is_string($customClass) && $customClass !== '' && class_exists($customClass)) {
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

        $this->publishBillingMigrations(
            __DIR__.'/Database/Migrations',
            database_path('migrations'),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\SyncPlansCommand::class,
                Console\Commands\BillingHealthCommand::class,
                Console\Commands\ProcessRenewalsCommand::class,
                Console\Commands\ProcessInvoicesCommand::class,
                Console\Commands\MpesaSetupCommand::class,
                Console\Commands\ReconcileWithPayOrchestraCommand::class,
                Console\Commands\PayOrchestraSmokeTestCommand::class,
            ]);
        }

        $this->validateConfig();
        $this->configureRateLimiting();
        $this->registerMiddleware();
        $this->registerRoutes();
        $this->registerWebhookRoutes();
        $this->registerUssdRoutes();
        $this->registerBladeDirectives();
    }

    /**
     * Register the package's middleware aliases on the router so consumers
     * can use `subscribed`, `feature:`, `plan:`, and `usage:` directly in
     * route definitions without manual wiring in their HTTP kernel.
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('subscribed', RequireSubscription::class);
        $router->aliasMiddleware('feature', CheckFeatureAccess::class);
        $router->aliasMiddleware('plan', CheckPlanAccess::class);
        $router->aliasMiddleware('usage', CheckUsageLimit::class);
    }

    /**
     * Canonical dependency order for the package's migrations.
     *
     * Sourced by table name suffix (everything after `create_billing_`).
     * Each entry MUST come after every table its FKs reference. Two source
     * files have FKs that contradict their numeric prefix:
     *
     *   - 000007_payments references `billing_invoices` (table from 000008)
     *   - 000012_coupon_redemptions references `coupons`, but alphabetical
     *     ordering on the published filename ranks `coupon_redemptions`
     *     before `coupons` (the `_` comes before `s`).
     *
     * Listing the order explicitly here means we never have to rename or
     * modify the shipped migration files — consumers who already published
     * v0.0.3 keep their existing rows in the migrations table untouched.
     *
     * @var list<string>
     */
    protected const MIGRATION_ORDER = [
        'plans',
        'features',
        'subscriptions',
        'subscription_addons',
        'usage_records',
        'usage_events',
        'invoices',
        'invoice_items',
        'payments',
        'coupons',
        'promotion_codes',
        'coupon_redemptions',
        'payment_tokens',
    ];

    /**
     * Publish migrations with unique, dependency-correct timestamps.
     *
     * Laravel's stock `publishesMigrations()` strips the source migration's
     * date prefix and replaces it with `now()->format('Y_m_d_His')`. Every
     * file is published in the same second, so they all collide on the new
     * timestamp and the migrator falls back to alphabetical order — at which
     * point `coupon_redemptions` runs before `coupons`, `invoice_items` and
     * `payments` run before `invoices`, and fresh `migrate` blows up on the
     * missing FK target.
     *
     * Walk the source files in `MIGRATION_ORDER` instead of letting the
     * filesystem dictate order, then assign each file a timestamp one second
     * apart so the published filenames preserve dependency order even after
     * Laravel's prefix-stripping.
     */
    protected function publishBillingMigrations(string $from, string $to): void
    {
        $available = [];

        foreach (glob($from.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_create_billing_/', '', basename($file, '.php'));
            $name = preg_replace('/_table$/', '', (string) $name);

            if ($name === null) {
                continue;
            }

            $available[$name] = $file;
        }

        $now = now();
        $paths = [];
        $i = 0;

        foreach (static::MIGRATION_ORDER as $table) {
            if (! isset($available[$table])) {
                continue;
            }

            $file = $available[$table];
            $stripped = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
            $timestamp = $now->copy()->addSeconds($i)->format('Y_m_d_His');
            $paths[$file] = $to.DIRECTORY_SEPARATOR.$timestamp.'_'.$stripped;

            unset($available[$table]);
            $i++;
        }

        // Defensive: any new migration added to the package but missing from
        // MIGRATION_ORDER still gets published, just appended at the end.
        foreach ($available as $file) {
            $stripped = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
            $timestamp = $now->copy()->addSeconds($i)->format('Y_m_d_His');
            $paths[$file] = $to.DIRECTORY_SEPARATOR.$timestamp.'_'.$stripped;
            $i++;
        }

        $this->publishes($paths, 'billing-migrations');
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
     *
     * @return array{prefix: string, middleware: array<int, string>}
     */
    protected function routeConfiguration(): array
    {
        $middlewareConfig = config('billing.routes.middleware', ['api']);
        /** @var array<int, string> $middleware */
        $middleware = is_array($middlewareConfig)
            ? array_values(array_filter($middlewareConfig, fn ($v): bool => is_string($v)))
            : ['api'];

        $rateLimit = config('billing.routes.rate_limit');

        if ($rateLimit) {
            $middleware[] = 'throttle:billing';
        }

        $prefix = config('billing.routes.prefix', 'api/billing');

        return [
            'prefix' => is_string($prefix) ? $prefix : 'api/billing',
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

        $prefixValue = config('billing.webhooks.prefix', 'billing/webhooks');
        $prefix = is_string($prefixValue) ? $prefixValue : 'billing/webhooks';

        $rateLimitValue = config('billing.webhooks.rate_limit', 60);
        $rateLimit = is_numeric($rateLimitValue) ? (int) $rateLimitValue : 60;

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
                Route::post('/tkash', [WebhookController::class, 'tkash'])->name('billing.webhooks.tkash');
                Route::post('/kcb', [WebhookController::class, 'kcb'])->name('billing.webhooks.kcb');
                Route::post('/jenga', [WebhookController::class, 'jenga'])->name('billing.webhooks.jenga');
                Route::post('/coopbank', [WebhookController::class, 'coopbank'])->name('billing.webhooks.coopbank');
                Route::post('/stanbic', [WebhookController::class, 'stanbic'])->name('billing.webhooks.stanbic');
                Route::post('/ncba', [WebhookController::class, 'ncba'])->name('billing.webhooks.ncba');
                Route::post('/intasend', [WebhookController::class, 'intasend'])->name('billing.webhooks.intasend');
                Route::post('/payorchestra', [WebhookController::class, 'payorchestra'])->name('billing.webhooks.payorchestra');
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
        $maxAttemptsValue = config('billing.routes.rate_limit', 60);
        $maxAttempts = is_numeric($maxAttemptsValue) ? (int) $maxAttemptsValue : 60;

        if ($maxAttempts <= 0) {
            return;
        }

        RateLimiter::for('billing', function (Request $request) use ($maxAttempts) {
            $user = $request->user();
            $identifier = $user?->getAuthIdentifier();

            $key = (is_string($identifier) || is_int($identifier)) && (string) $identifier !== ''
                ? (string) $identifier
                : (string) $request->ip();

            return Limit::perMinute($maxAttempts)->by($key);
        });
    }

    /**
     * Validate billing configuration on boot.
     */
    protected function validateConfig(): void
    {
        $providerValue = config('billing.default_provider');
        $provider = is_string($providerValue) ? $providerValue : '';
        $validProviders = ['payorchestra', 'mpesa', 'paystack', 'flutterwave', 'pesapal', 'airtel', 'tkash', 'kcb', 'jenga', 'coopbank', 'stanbic', 'ncba', 'intasend', 'manual'];

        if ($provider !== '' && ! in_array($provider, $validProviders, true)) {
            Log::warning("Billing: Unrecognized default provider '{$provider}'. Valid providers: ".implode(', ', $validProviders));
        }

        $currencyValue = config('billing.currency');
        $currency = is_string($currencyValue) ? $currencyValue : '';

        if ($currency !== '' && strlen($currency) !== 3) {
            Log::warning("Billing: Currency '{$currency}' should be a 3-letter ISO 4217 code (e.g., KES, USD).");
        }
    }

    /**
     * Register Blade directives for feature gating.
     */
    protected function registerBladeDirectives(): void
    {
        // @feature('ocr_scanning') ... @endfeature
        Blade::if('feature', function (string $featureSlug): bool {
            $user = Auth::user();

            if ($user === null) {
                return false;
            }

            $billable = $this->resolveBillable($user);

            if (! is_object($billable)) {
                return false;
            }

            // Admin bypass
            if (method_exists($billable, 'isBillingAdmin') && $billable->isBillingAdmin() === true) {
                return true;
            }

            return method_exists($billable, 'hasFeature') && $billable->hasFeature($featureSlug) === true;
        });

        // @plan('professional') ... @endplan
        Blade::if('plan', function (string $planSlug): bool {
            $user = Auth::user();

            if ($user === null) {
                return false;
            }

            $billable = $this->resolveBillableForPlan($user);

            if (! is_object($billable)) {
                return false;
            }

            // Admin bypass
            if (method_exists($billable, 'isBillingAdmin') && $billable->isBillingAdmin() === true) {
                return true;
            }

            return method_exists($billable, 'onPlan') && $billable->onPlan($planSlug) === true;
        });
    }

    /**
     * Resolve a billable instance from the current user for feature checks.
     */
    protected function resolveBillable(object $user): ?object
    {
        if (method_exists($user, 'hasFeature')) {
            return $user;
        }

        $relationValue = config('billing.billable_relation', 'company');
        $relation = is_string($relationValue) ? $relationValue : 'company';

        if (! method_exists($user, $relation)) {
            return null;
        }

        $resolved = $user->{$relation};

        return is_object($resolved) ? $resolved : null;
    }

    /**
     * Resolve a billable instance from the current user for plan checks.
     */
    protected function resolveBillableForPlan(object $user): ?object
    {
        if (method_exists($user, 'onPlan')) {
            return $user;
        }

        $relationValue = config('billing.billable_relation', 'company');
        $relation = is_string($relationValue) ? $relationValue : 'company';

        if (! method_exists($user, $relation)) {
            return null;
        }

        $resolved = $user->{$relation};

        return is_object($resolved) ? $resolved : null;
    }
}
