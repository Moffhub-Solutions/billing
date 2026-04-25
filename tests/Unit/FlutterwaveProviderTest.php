<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\FlutterwaveProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class FlutterwaveProviderTest extends BaseTestCase
{
    protected FlutterwaveProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FlutterwaveProvider(
            secretKey: 'FLWSECK_TEST-xxxxx',
            publicKey: 'FLWPUBK_TEST-xxxxx',
            webhookSecret: 'flw_webhook_secret',
        );
    }

    public function test_get_name(): void
    {
        $this->assertEquals('flutterwave', $this->provider->getName());
    }

    public function test_is_configured(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_secret(): void
    {
        $provider = new FlutterwaveProvider('');
        $this->assertFalse($provider->isConfigured());
    }

    public function test_initialize_payment(): void
    {
        Http::fake([
            '*/payments' => Http::response([
                'status' => 'success',
                'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/xxx'],
            ]),
        ]);

        $result = $this->provider->charge(750000, 'KES', [
            'email' => 'customer@example.com',
            'description' => 'Subscription',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertNotNull($result['metadata']['payment_link']);
    }

    public function test_charge_requires_email(): void
    {
        $result = $this->provider->charge(100000, 'KES', []);
        $this->assertFalse($result['success']);
    }

    public function test_charge_converts_cents_to_whole_units(): void
    {
        Http::fake([
            '*/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'url']]),
        ]);

        $this->provider->charge(750000, 'KES', ['email' => 'test@test.com']);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            if (! is_array($body)) {
                return false;
            }

            return ($body['amount'] ?? null) == 7500;
        });
    }

    public function test_verify_payment_status(): void
    {
        Http::fake([
            '*/transactions/*/verify' => Http::response([
                'status' => 'success',
                'data' => ['status' => 'successful'],
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('12345'));
    }

    public function test_refund(): void
    {
        Http::fake([
            '*/transactions/*/refund' => Http::response([
                'status' => 'success',
                'data' => ['id' => 54321],
            ]),
        ]);

        $result = $this->provider->refund('12345', 250000);
        $this->assertTrue($result['success']);
        $this->assertEquals('completed', $result['status']);
    }

    public function test_verify_webhook_valid(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_VERIF_HASH' => 'flw_webhook_secret',
        ], '{}');

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_VERIF_HASH' => 'wrong_secret',
        ], '{}');

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_charge_completed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'event' => 'charge.completed',
            'data' => [
                'id' => 12345,
                'tx_ref' => 'FLW-REF-001',
                'flw_ref' => 'FLW-MOCK-xxx',
                'amount' => 7500,
                'currency' => 'KES',
                'status' => 'successful',
                'payment_type' => 'mpesa',
            ],
        ]);

        $parsed = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $parsed['event']);
        $this->assertEquals('12345', $parsed['provider_payment_id']);
        $this->assertEquals('completed', $parsed['status']);
        $this->assertEquals(750000, $parsed['amount']); // 7500 * 100
        $this->assertEquals('KES', $parsed['currency']);
        $this->assertEquals('mpesa', $parsed['metadata']['payment_type']);
    }

    public function test_payment_manager_creates_flutterwave_driver(): void
    {
        config(['billing.providers.flutterwave.secret_key' => 'FLWSECK_TEST']);

        $manager = app(PaymentManager::class);
        $driver = $manager->driver('flutterwave');

        $this->assertInstanceOf(FlutterwaveProvider::class, $driver);
    }
}
