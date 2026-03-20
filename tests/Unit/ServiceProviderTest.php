<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Contracts\FeatureResolverInterface;
use Moffhub\Billing\Facades\Billing;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\BillingService;
use Moffhub\Billing\Services\FeatureResolver;
use Moffhub\Billing\Services\UsageService;
use Moffhub\Billing\Tests\BaseTestCase;

class ServiceProviderTest extends BaseTestCase
{
    public function test_registers_billing_service(): void
    {
        $service = $this->app->make(BillingService::class);

        $this->assertInstanceOf(BillingService::class, $service);
    }

    public function test_registers_payment_manager(): void
    {
        $manager = $this->app->make(PaymentManager::class);

        $this->assertInstanceOf(PaymentManager::class, $manager);
    }

    public function test_registers_feature_resolver(): void
    {
        $resolver = $this->app->make(FeatureResolverInterface::class);

        $this->assertInstanceOf(FeatureResolver::class, $resolver);

        // Also test the concrete binding
        $concrete = $this->app->make(FeatureResolver::class);
        $this->assertInstanceOf(FeatureResolver::class, $concrete);
    }

    public function test_registers_usage_service(): void
    {
        $service = $this->app->make(UsageService::class);

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
}
