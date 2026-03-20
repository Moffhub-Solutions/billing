<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Moffhub\Billing\BillingServiceProvider;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Orchestra\Testbench\TestCase;

abstract class BaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            BillingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('billing.currency', 'KES');
        $app['config']->set('billing.default_provider', 'manual');
        $app['config']->set('billing.billable_model', Company::class);
        $app['config']->set('billing.features.cache_ttl', 0); // Disable cache in tests
        $app['config']->set('billing.security.encrypt_at_rest', false); // Off by default in tests
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../src/Database/Migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/Migrations');
    }
}
