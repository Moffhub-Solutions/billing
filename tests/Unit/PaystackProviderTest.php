<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\PaystackProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class PaystackProviderTest extends BaseTestCase
{
    protected PaystackProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new PaystackProvider(
            secretKey: 'sk_test_xxxxx',
            publicKey: 'pk_test_xxxxx',
            webhookSecret: 'whsec_test_xxxxx',
        );
    }

    public function test_get_name(): void
    {
        $this->assertEquals('paystack', $this->provider->getName());
    }

    public function test_is_configured(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_secret(): void
    {
        $provider = new PaystackProvider('');
        $this->assertFalse($provider->isConfigured());
    }

    public function test_initialize_transaction(): void
    {
        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/xxx',
                    'access_code' => 'ACCESS_CODE',
                    'reference' => 'REF-001',
                ],
            ]),
        ]);

        $result = $this->provider->charge(750000, 'KES', ['email' => 'customer@example.com']);

        $this->assertTrue($result['success']);
        $this->assertEquals('REF-001', $result['provider_payment_id']);
        $this->assertEquals('pending', $result['status']);
        $this->assertNotNull($result['metadata']['authorization_url']);
    }

    public function test_charge_requires_email(): void
    {
        $result = $this->provider->charge(100000, 'KES', []);
        $this->assertFalse($result['success']);
    }

    public function test_charge_with_authorization_code(): void
    {
        Http::fake([
            '*/transaction/charge_authorization' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => 'REF-002',
                    'id' => 12345,
                    'status' => 'success',
                ],
            ]),
        ]);

        $result = $this->provider->charge(500000, 'KES', [
            'email' => 'customer@example.com',
            'authorization_code' => 'AUTH_xxxxx',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('completed', $result['status']);
    }

    public function test_verify_payment_status(): void
    {
        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success'],
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('REF-001'));
    }

    public function test_refund(): void
    {
        Http::fake([
            '*/refund' => Http::response([
                'status' => true,
                'data' => ['transaction' => ['id' => 12345]],
            ]),
        ]);

        $result = $this->provider->refund('REF-001', 250000);
        $this->assertTrue($result['success']);
    }

    public function test_verify_webhook_valid_signature(): void
    {
        $payload = '{"event":"charge.success","data":{"reference":"REF-001"}}';
        $signature = hash_hmac('sha512', $payload, 'whsec_test_xxxxx');

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $payload);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid_signature(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => 'invalid',
        ], '{}');

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_charge_success(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'REF-001',
                'amount' => 750000,
                'currency' => 'KES',
                'channel' => 'card',
                'authorization' => ['authorization_code' => 'AUTH_xxx'],
            ],
        ]);

        $parsed = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $parsed['event']);
        $this->assertEquals('REF-001', $parsed['provider_payment_id']);
        $this->assertEquals('completed', $parsed['status']);
        $this->assertEquals(750000, $parsed['amount']);
    }

    public function test_payment_manager_creates_paystack_driver(): void
    {
        config(['billing.providers.paystack.secret_key' => 'sk_test']);

        $manager = app(PaymentManager::class);
        $driver = $manager->driver('paystack');

        $this->assertInstanceOf(PaystackProvider::class, $driver);
    }
}
