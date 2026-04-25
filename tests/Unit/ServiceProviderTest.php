<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Routing\Router;
use Moffhub\Billing\BillingServiceProvider;
use Moffhub\Billing\Contracts\FeatureResolverInterface;
use Moffhub\Billing\Facades\Billing;
use Moffhub\Billing\Http\Middleware\CheckFeatureAccess;
use Moffhub\Billing\Http\Middleware\CheckPlanAccess;
use Moffhub\Billing\Http\Middleware\CheckUsageLimit;
use Moffhub\Billing\Http\Middleware\RequireSubscription;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\BillingService;
use Moffhub\Billing\Services\FeatureResolver;
use Moffhub\Billing\Services\UsageService;
use Moffhub\Billing\Tests\BaseTestCase;

class ServiceProviderTest extends BaseTestCase
{
    public function test_registers_billing_service(): void
    {
        $service = $this->app()->make(BillingService::class);

        $this->assertInstanceOf(BillingService::class, $service);
    }

    public function test_registers_payment_manager(): void
    {
        $manager = $this->app()->make(PaymentManager::class);

        $this->assertInstanceOf(PaymentManager::class, $manager);
    }

    public function test_registers_feature_resolver(): void
    {
        $resolver = $this->app()->make(FeatureResolverInterface::class);

        $this->assertInstanceOf(FeatureResolver::class, $resolver);

        // Also test the concrete binding
        $concrete = $this->app()->make(FeatureResolver::class);
        $this->assertInstanceOf(FeatureResolver::class, $concrete);
    }

    public function test_registers_usage_service(): void
    {
        $service = $this->app()->make(UsageService::class);

        $this->assertInstanceOf(UsageService::class, $service);
    }

    public function test_merges_config(): void
    {
        // The BaseTestCase sets currency to KES
        $this->assertEquals('KES', config('billing.currency'));
        $this->assertEquals('manual', config('billing.default_provider'));
    }

    public function test_registers_facade(): void
    {
        $service = Billing::getFacadeRoot();

        $this->assertInstanceOf(BillingService::class, $service);
    }

    public function test_registers_middleware_aliases(): void
    {
        $router = $this->app()->make(Router::class);
        $aliases = $router->getMiddleware();

        $this->assertSame(RequireSubscription::class, $aliases['subscribed'] ?? null);
        $this->assertSame(CheckFeatureAccess::class, $aliases['feature'] ?? null);
        $this->assertSame(CheckPlanAccess::class, $aliases['plan'] ?? null);
        $this->assertSame(CheckUsageLimit::class, $aliases['usage'] ?? null);
    }

    public function test_publish_helper_orders_migrations_by_dependency(): void
    {
        // Walk the source directory through the same logic vendor:publish
        // would, then assert FK targets land before the tables that
        // reference them. Catches future regressions where someone adds a
        // migration without updating MIGRATION_ORDER.
        $provider = new class($this->app()) extends BillingServiceProvider
        {
            /**
             * @return array<string, string>
             */
            public function publishedPaths(string $from, string $to): array
            {
                $captured = [];

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
                $i = 0;

                foreach (self::MIGRATION_ORDER as $table) {
                    if (! isset($available[$table])) {
                        continue;
                    }

                    $file = $available[$table];
                    $stripped = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
                    $timestamp = $now->copy()->addSeconds($i)->format('Y_m_d_His');
                    $captured[$file] = $to.DIRECTORY_SEPARATOR.$timestamp.'_'.$stripped;

                    unset($available[$table]);
                    $i++;
                }

                foreach ($available as $file) {
                    $stripped = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
                    $timestamp = $now->copy()->addSeconds($i)->format('Y_m_d_His');
                    $captured[$file] = $to.DIRECTORY_SEPARATOR.$timestamp.'_'.$stripped;
                    $i++;
                }

                return $captured;
            }
        };

        $paths = $provider->publishedPaths(
            __DIR__.'/../../src/Database/Migrations',
            '/tmp/migrations',
        );

        // Sort the published filenames as Laravel's migrator would.
        $published = array_values($paths);
        sort($published);
        $tables = array_map(
            fn (string $path) => preg_replace('/^.*?\d{4}_\d{2}_\d{2}_\d{6}_create_billing_(.+?)_table\.php$/', '$1', $path),
            $published,
        );

        // Each FK target must come before the table that references it.
        $this->assertLessThan(array_search('subscriptions', $tables, true), array_search('plans', $tables, true));
        $this->assertLessThan(array_search('subscription_addons', $tables, true), array_search('subscriptions', $tables, true));
        $this->assertLessThan(array_search('subscription_addons', $tables, true), array_search('features', $tables, true));
        $this->assertLessThan(array_search('payments', $tables, true), array_search('invoices', $tables, true));
        $this->assertLessThan(array_search('invoice_items', $tables, true), array_search('invoices', $tables, true));
        $this->assertLessThan(array_search('coupon_redemptions', $tables, true), array_search('coupons', $tables, true));
        $this->assertLessThan(array_search('coupon_redemptions', $tables, true), array_search('promotion_codes', $tables, true));
        $this->assertLessThan(array_search('promotion_codes', $tables, true), array_search('coupons', $tables, true));
    }
}
