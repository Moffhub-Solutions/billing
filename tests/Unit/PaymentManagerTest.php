<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\ManualProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class PaymentManagerTest extends BaseTestCase
{
    protected PaymentManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->app->make(PaymentManager::class);
    }

    public function test_create_manual_driver(): void
    {
        $driver = $this->manager->driver('manual');

        $this->assertInstanceOf(PaymentProviderInterface::class, $driver);
        $this->assertInstanceOf(ManualProvider::class, $driver);
    }

    public function test_get_default_driver(): void
    {
        // Config sets default_provider to 'manual' in BaseTestCase
        $this->assertEquals('manual', $this->manager->getDefaultDriver());
    }

    public function test_mpesa_driver_not_implemented(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('M-Pesa provider not yet implemented');

        $this->manager->driver('mpesa');
    }

    public function test_paystack_driver_not_implemented(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Paystack provider not yet implemented');

        $this->manager->driver('paystack');
    }

    public function test_get_available_providers(): void
    {
        $providers = $this->manager->getAvailableProviders();

        $this->assertContains('mpesa', $providers);
        $this->assertContains('paystack', $providers);
        $this->assertContains('flutterwave', $providers);
        $this->assertContains('pesapal', $providers);
        $this->assertContains('manual', $providers);
        $this->assertCount(5, $providers);
    }

    public function test_is_provider_configured_manual(): void
    {
        $this->assertTrue($this->manager->isProviderConfigured('manual'));
    }

    public function test_is_provider_configured_mpesa_missing(): void
    {
        // No mpesa config set
        $this->assertFalse($this->manager->isProviderConfigured('mpesa'));
    }

    public function test_is_provider_configured_mpesa_present(): void
    {
        $this->app['config']->set('billing.providers.mpesa', [
            'consumer_key' => 'test_key',
            'consumer_secret' => 'test_secret',
        ]);

        $this->assertTrue($this->manager->isProviderConfigured('mpesa'));
    }
}
