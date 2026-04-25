<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Moffhub\Billing\BillingServiceProvider;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Orchestra\Testbench\TestCase;

abstract class BaseTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BillingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $config->set('billing.currency', 'KES');
        $config->set('billing.default_provider', 'manual');
        $config->set('billing.billable_model', Company::class);
        $config->set('billing.features.cache_ttl', 0); // Disable cache in tests
        $config->set('billing.security.encrypt_at_rest', false); // Off by default in tests
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../src/Database/Migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/Migrations');
    }

    /**
     * Resolve the application's config repository, throwing if it's missing.
     */
    protected function config(): ConfigRepository
    {
        if (! $this->app instanceof Application) {
            throw new \RuntimeException('Application not booted in test.');
        }

        return $this->app->make('config');
    }

    /**
     * Set a config value during a test (typed wrapper around $this->app['config']->set).
     */
    protected function setConfig(string $key, mixed $value): void
    {
        $this->config()->set($key, $value);
    }

    /**
     * Get the app instance, throwing if it's not booted.
     */
    protected function app(): Application
    {
        if (! $this->app instanceof Application) {
            throw new \RuntimeException('Application not booted in test.');
        }

        return $this->app;
    }

    /**
     * Run an artisan command and return a PendingCommand (typed wrapper around $this->artisan).
     *
     * Without this wrapper, callers need to either chain everything in one
     * expression (no intermediate variable so PHPStan can't analyze the
     * return) or do manual instanceof checks.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function billingArtisan(string $command, array $parameters = []): PendingCommand
    {
        $result = $this->artisan($command, $parameters);

        if (! $result instanceof PendingCommand) {
            throw new \RuntimeException('artisan() did not return a PendingCommand.');
        }

        return $result;
    }
}
