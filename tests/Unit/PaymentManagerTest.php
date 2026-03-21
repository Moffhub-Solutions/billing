<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\ManualProvider;
use Moffhub\Billing\Providers\MpesaProvider;
use Moffhub\Billing\Providers\PaystackProvider;
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

    public function test_mpesa_driver_creates_provider(): void
    {
        $this->app['config']->set('billing.providers.mpesa', [
            'consumer_key' => 'test_key',
            'consumer_secret' => 'test_secret',
            'shortcode' => '174379',
            'passkey' => 'test_passkey',
        ]);

        $driver = $this->manager->driver('mpesa');

        $this->assertInstanceOf(MpesaProvider::class, $driver);
        $this->assertEquals('mpesa', $driver->getName());
    }

    public function test_paystack_driver_creates_provider(): void
    {
        $this->app['config']->set('billing.providers.paystack.secret_key', 'sk_test');

        $driver = $this->manager->driver('paystack');

        $this->assertInstanceOf(PaystackProvider::class, $driver);
        $this->assertEquals('paystack', $driver->getName());
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
