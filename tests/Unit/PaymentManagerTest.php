<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\AirtelMoneyProvider;
use Moffhub\Billing\Providers\CoopBankProvider;
use Moffhub\Billing\Providers\IntaSendProvider;
use Moffhub\Billing\Providers\JengaProvider;
use Moffhub\Billing\Providers\KcbBuniProvider;
use Moffhub\Billing\Providers\ManualProvider;
use Moffhub\Billing\Providers\MpesaProvider;
use Moffhub\Billing\Providers\NcbaProvider;
use Moffhub\Billing\Providers\PaystackProvider;
use Moffhub\Billing\Providers\StanbicProvider;
use Moffhub\Billing\Providers\TkashProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class PaymentManagerTest extends BaseTestCase
{
    protected PaymentManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $manager = $this->app()->make(PaymentManager::class);
        $this->assertInstanceOf(PaymentManager::class, $manager);
        $this->manager = $manager;
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
        $this->setConfig('billing.providers.mpesa', [
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
        $this->setConfig('billing.providers.paystack.secret_key', 'sk_test');

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
        $this->assertContains('airtel', $providers);
        $this->assertContains('tkash', $providers);
        $this->assertContains('kcb', $providers);
        $this->assertContains('jenga', $providers);
        $this->assertContains('coopbank', $providers);
        $this->assertContains('stanbic', $providers);
        $this->assertContains('ncba', $providers);
        $this->assertContains('intasend', $providers);
        $this->assertContains('payorchestra', $providers);
        $this->assertContains('manual', $providers);
        $this->assertCount(14, $providers);
    }

    public function test_is_provider_configured_manual(): void
    {
        $this->assertTrue($this->manager->isProviderConfigured('manual'));
    }

    // ─── Enabled Providers / Payment Options ───────────────────────────

    public function test_get_enabled_providers_defaults_to_configured(): void
    {
        $this->setConfig('billing.enabled_providers', []);
        $this->setConfig('billing.providers.mpesa', ['consumer_key' => 'k', 'consumer_secret' => 's']);

        $enabled = $this->manager->getEnabledProviders();

        $this->assertContains('mpesa', $enabled);
        $this->assertContains('manual', $enabled);
        // Unconfigured providers are excluded.
        $this->assertNotContains('airtel', $enabled);
    }

    public function test_get_enabled_providers_respects_curated_list_and_order(): void
    {
        $this->setConfig('billing.providers.mpesa', ['consumer_key' => 'k', 'consumer_secret' => 's']);
        $this->setConfig('billing.providers.airtel', ['client_id' => 'i', 'client_secret' => 's']);
        $this->setConfig('billing.enabled_providers', ['airtel', 'mpesa']);

        $this->assertSame(['airtel', 'mpesa'], $this->manager->getEnabledProviders());
    }

    public function test_get_enabled_providers_drops_unconfigured_from_curated_list(): void
    {
        $this->setConfig('billing.providers.mpesa', ['consumer_key' => 'k', 'consumer_secret' => 's']);
        // airtel listed but NOT configured
        $this->setConfig('billing.enabled_providers', ['mpesa', 'airtel']);

        $this->assertSame(['mpesa'], $this->manager->getEnabledProviders());
    }

    public function test_get_payment_options_shape(): void
    {
        $this->setConfig('billing.providers.tkash', ['consumer_key' => 'k', 'consumer_secret' => 's', 'consumer_id' => '600100']);
        $this->setConfig('billing.enabled_providers', ['tkash']);

        $options = $this->manager->getPaymentOptions();

        $this->assertSame([
            ['provider' => 'tkash', 'label' => 'T-Kash', 'method' => 'tkash'],
        ], $options);
    }

    public function test_payment_options_include_cash_by_default(): void
    {
        $this->setConfig('billing.enabled_providers', []);

        $providers = array_column($this->manager->getPaymentOptions(), 'provider');

        $this->assertContains('manual', $providers);
    }

    public function test_offer_cash_false_hides_manual_from_options(): void
    {
        $this->setConfig('billing.enabled_providers', []);
        $this->setConfig('billing.offer_cash', false);

        $providers = array_column($this->manager->getPaymentOptions(), 'provider');

        $this->assertNotContains('manual', $providers);
    }

    public function test_offer_cash_false_still_honors_explicit_manual_in_curated_list(): void
    {
        $this->setConfig('billing.providers.mpesa', ['consumer_key' => 'k', 'consumer_secret' => 's']);
        $this->setConfig('billing.enabled_providers', ['mpesa', 'manual']);
        $this->setConfig('billing.offer_cash', false);

        $providers = array_column($this->manager->getPaymentOptions(), 'provider');

        // Operator explicitly listed manual, so the toggle does not strip it.
        $this->assertContains('manual', $providers);
    }

    public function test_is_provider_configured_mpesa_missing(): void
    {
        // No mpesa config set
        $this->assertFalse($this->manager->isProviderConfigured('mpesa'));
    }

    public function test_is_provider_configured_mpesa_present(): void
    {
        $this->setConfig('billing.providers.mpesa', [
            'consumer_key' => 'test_key',
            'consumer_secret' => 'test_secret',
        ]);

        $this->assertTrue($this->manager->isProviderConfigured('mpesa'));
    }

    // ─── New Provider Drivers ──────────────────────────────────────────

    public function test_airtel_driver_creates_provider(): void
    {
        $this->setConfig('billing.providers.airtel', [
            'client_id' => 'test_id',
            'client_secret' => 'test_secret',
        ]);

        $driver = $this->manager->driver('airtel');

        $this->assertInstanceOf(AirtelMoneyProvider::class, $driver);
        $this->assertEquals('airtel', $driver->getName());
    }

    public function test_tkash_driver_creates_provider(): void
    {
        $this->setConfig('billing.providers.tkash', [
            'consumer_key' => 'test_key',
            'consumer_secret' => 'test_secret',
            'consumer_id' => '600100',
        ]);

        $driver = $this->manager->driver('tkash');

        $this->assertInstanceOf(TkashProvider::class, $driver);
        $this->assertEquals('tkash', $driver->getName());
    }

    public function test_kcb_driver_creates_provider(): void
    {
        $this->setConfig('billing.providers.kcb', [
            'api_key' => 'test_key',
            'api_secret' => 'test_secret',
        ]);

        $driver = $this->manager->driver('kcb');

        $this->assertInstanceOf(KcbBuniProvider::class, $driver);
        $this->assertEquals('kcb', $driver->getName());
    }

    public function test_jenga_driver_creates_provider(): void
    {
        $this->setConfig('billing.providers.jenga', [
            'api_key' => 'test_key',
            'consumer_secret' => 'test_secret',
            'merchant_code' => 'MERCH001',
        ]);

        $driver = $this->manager->driver('jenga');

        $this->assertInstanceOf(JengaProvider::class, $driver);
        $this->assertEquals('jenga', $driver->getName());
    }

    public function test_coopbank_driver_creates_provider(): void
    {
        $this->setConfig('billing.providers.coopbank', [
            'consumer_key' => 'test_key',
            'consumer_secret' => 'test_secret',
        ]);

        $driver = $this->manager->driver('coopbank');

        $this->assertInstanceOf(CoopBankProvider::class, $driver);
        $this->assertEquals('coopbank', $driver->getName());
    }

    public function test_stanbic_driver_creates_provider(): void
    {
        $this->setConfig('billing.providers.stanbic', [
            'api_key' => 'test_key',
            'api_secret' => 'test_secret',
        ]);

        $driver = $this->manager->driver('stanbic');

        $this->assertInstanceOf(StanbicProvider::class, $driver);
        $this->assertEquals('stanbic', $driver->getName());
    }

    public function test_ncba_driver_creates_provider(): void
    {
        $this->setConfig('billing.providers.ncba', [
            'api_key' => 'test_key',
        ]);

        $driver = $this->manager->driver('ncba');

        $this->assertInstanceOf(NcbaProvider::class, $driver);
        $this->assertEquals('ncba', $driver->getName());
    }

    // ─── New Provider Configuration Checks ─────────────────────────────

    public function test_is_provider_configured_airtel(): void
    {
        $this->setConfig('billing.providers.airtel', [
            'client_id' => 'test',
            'client_secret' => 'test',
        ]);

        $this->assertTrue($this->manager->isProviderConfigured('airtel'));
    }

    public function test_is_provider_not_configured_airtel(): void
    {
        $this->assertFalse($this->manager->isProviderConfigured('airtel'));
    }

    public function test_is_provider_configured_kcb(): void
    {
        $this->setConfig('billing.providers.kcb', [
            'api_key' => 'test',
            'api_secret' => 'test',
        ]);

        $this->assertTrue($this->manager->isProviderConfigured('kcb'));
    }

    public function test_is_provider_configured_ncba(): void
    {
        $this->setConfig('billing.providers.ncba', [
            'api_key' => 'test',
        ]);

        $this->assertTrue($this->manager->isProviderConfigured('ncba'));
    }

    public function test_intasend_driver_creates_provider(): void
    {
        $this->setConfig('billing.providers.intasend', [
            'publishable_key' => 'ISPubKey_test_123',
            'secret_key' => 'ISSecretKey_test_123',
        ]);

        $driver = $this->manager->driver('intasend');

        $this->assertInstanceOf(IntaSendProvider::class, $driver);
        $this->assertEquals('intasend', $driver->getName());
    }

    public function test_is_provider_configured_intasend(): void
    {
        $this->setConfig('billing.providers.intasend', [
            'publishable_key' => 'test',
            'secret_key' => 'test',
        ]);

        $this->assertTrue($this->manager->isProviderConfigured('intasend'));
    }

    public function test_is_provider_not_configured_intasend(): void
    {
        $this->assertFalse($this->manager->isProviderConfigured('intasend'));
    }
}
