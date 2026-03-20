<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\PesapalProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class PesapalProviderTest extends BaseTestCase
{
    protected PesapalProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new PesapalProvider(
            consumerKey: 'test_key',
            consumerSecret: 'test_secret',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/callback',
            ipnId: 'test-ipn-id-123',
        );
    }

    public function test_get_name(): void
    {
        $this->assertEquals('pesapal', $this->provider->getName());
    }

    public function test_is_configured(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new PesapalProvider('', '');
        $this->assertFalse($provider->isConfigured());
    }

    public function test_get_access_token(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'pesapal_token_123', 'status' => '200']),
        ]);

        $token = $this->provider->getAccessToken();
        $this->assertEquals('pesapal_token_123', $token);
    }

    public function test_submit_order_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'token']),
            '*/api/Transactions/SubmitOrderRequest' => Http::response([
                'order_tracking_id' => 'OTR-12345',
                'merchant_reference' => 'REF-001',
                'redirect_url' => 'https://pay.pesapal.com/pay/12345',
                'error' => null,
                'status' => '200',
            ]),
        ]);

        $result = $this->provider->submitOrder(
            merchantReference: 'REF-001',
            amount: 7500.00,
            currency: 'KES',
            description: 'Subscription payment',
            email: 'customer@example.com',
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('OTR-12345', $result['provider_payment_id']);
        $this->assertEquals('pending', $result['status']);
        $this->assertNotNull($result['metadata']['redirect_url']);
    }

    public function test_charge_requires_email_or_phone(): void
    {
        $result = $this->provider->charge(750000, 'KES', []);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Email or phone', $result['metadata']['error']);
    }

    public function test_charge_with_email(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'token']),
            '*/api/Transactions/SubmitOrderRequest' => Http::response([
                'order_tracking_id' => 'OTR-001',
                'merchant_reference' => 'PSP-001',
                'redirect_url' => 'https://pay.pesapal.com/pay',
                'error' => null,
            ]),
        ]);

        $result = $this->provider->charge(750000, 'KES', ['email' => 'test@example.com']);
        $this->assertTrue($result['success']);
    }

    public function test_get_transaction_status(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'token']),
            '*/api/Transactions/GetTransactionStatus*' => Http::response([
                'status_code' => 1,
                'payment_method' => 'Mpesa',
                'amount' => 7500,
                'confirmation_code' => 'MPESA123',
                'currency' => 'KES',
            ]),
        ]);

        $status = $this->provider->getPaymentStatus('OTR-12345');
        $this->assertEquals('completed', $status);
    }

    public function test_get_payment_status_failed(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'token']),
            '*/api/Transactions/GetTransactionStatus*' => Http::response(['status_code' => 2]),
        ]);

        $this->assertEquals('failed', $this->provider->getPaymentStatus('OTR-fail'));
    }

    public function test_refund_request(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'token']),
            '*/api/Transactions/RefundRequest' => Http::response(['status' => 200, 'message' => 'Refund request received']),
        ]);

        $result = $this->provider->refund('MPESA123', 500000, ['confirmation_code' => 'MPESA123']);
        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
    }

    public function test_register_ipn_url(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'token']),
            '*/api/URLSetup/RegisterIPN' => Http::response([
                'url' => 'https://example.com/ipn',
                'ipn_id' => 'new-ipn-id-456',
                'ipn_status' => 1,
            ]),
        ]);

        $result = $this->provider->registerIpnUrl('https://example.com/ipn');
        $this->assertEquals('new-ipn-id-456', $result['ipn_id']);
    }

    public function test_verify_webhook_with_tracking_id(): void
    {
        $request = Request::create('/ipn', 'GET', ['OrderTrackingId' => 'OTR-123', 'OrderMerchantReference' => 'REF-001']);
        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_without_tracking_id_fails(): void
    {
        $request = Request::create('/ipn', 'GET', []);
        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_queries_status(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'token']),
            '*/api/Transactions/GetTransactionStatus*' => Http::response([
                'status_code' => 1,
                'amount' => 5000,
                'currency' => 'KES',
                'payment_method' => 'Mpesa',
                'confirmation_code' => 'MPESA456',
                'payment_status_description' => 'Completed',
            ]),
        ]);

        $request = Request::create('/ipn', 'GET', [
            'OrderTrackingId' => 'OTR-789',
            'OrderMerchantReference' => 'REF-002',
        ]);

        $parsed = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $parsed['event']);
        $this->assertEquals('OTR-789', $parsed['provider_payment_id']);
        $this->assertEquals('completed', $parsed['status']);
        $this->assertEquals(500000, $parsed['amount']);
        $this->assertEquals('MPESA456', $parsed['metadata']['confirmation_code']);
    }

    public function test_payment_manager_creates_pesapal_driver(): void
    {
        config([
            'billing.providers.pesapal.consumer_key' => 'key',
            'billing.providers.pesapal.consumer_secret' => 'secret',
        ]);

        $manager = app(PaymentManager::class);
        $driver = $manager->driver('pesapal');

        $this->assertInstanceOf(PesapalProvider::class, $driver);
        $this->assertEquals('pesapal', $driver->getName());
    }
}
