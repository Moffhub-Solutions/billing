<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Providers\TkashProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class TkashProviderTest extends BaseTestCase
{
    protected TkashProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new TkashProvider(
            consumerKey: 'test_consumer_key',
            consumerSecret: 'test_consumer_secret',
            consumerId: '600100',
            grantUsername: 'grant_user',
            grantPassword: 'grant_pass',
            b2cUsername: 'b2c_user',
            b2cPassword: 'b2c_pass',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/billing/webhooks/tkash',
            validationUrl: 'https://example.com/billing/tkash/validate',
            currency: 'KES',
        );
    }

    // ─── Configuration ─────────────────────────────────────────────────

    public function test_get_name(): void
    {
        $this->assertEquals('tkash', $this->provider->getName());
    }

    public function test_is_configured_with_all_credentials(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new TkashProvider(
            consumerKey: '',
            consumerSecret: '',
            consumerId: '',
        );

        $this->assertFalse($provider->isConfigured());
    }

    public function test_sandbox_resolves_uat_gateway_host(): void
    {
        Cache::flush();

        Http::fake([
            '*' => Http::response(['access_token' => 'test_token']),
        ]);

        $this->provider->getAccessToken();

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://uat.gw.mfs-tkl.com/token'));
    }

    // ─── OAuth Token ───────────────────────────────────────────────────

    public function test_get_access_token(): void
    {
        Cache::flush();

        Http::fake([
            '*/token*' => Http::response([
                'access_token' => 'tkash_test_token_123',
                'expires_in' => 3600,
                'token_type' => 'bearer',
            ]),
        ]);

        $token = $this->provider->getAccessToken();

        $this->assertEquals('tkash_test_token_123', $token);
        Http::assertSentCount(1);
    }

    public function test_access_token_is_cached(): void
    {
        Cache::flush();

        Http::fake([
            '*/token*' => Http::response([
                'access_token' => 'tkash_cached_token',
                'expires_in' => 3600,
            ]),
        ]);

        $this->provider->getAccessToken();
        $this->provider->getAccessToken();

        Http::assertSentCount(1);
    }

    // ─── Charge (C2B) ──────────────────────────────────────────────────

    public function test_charge_requires_phone(): void
    {
        $result = $this->provider->charge(10000, 'KES');

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
        $error = $result['metadata']['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('Phone number is required', $error);
    }

    public function test_charge_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/token*' => Http::response(['access_token' => 'test_token']),
            '*/consumer/v3/c2b/charge' => Http::response([
                'response' => [
                    'responseCode' => '0',
                    'transactionId' => 'TK-12345',
                    'referenceId' => 'INV-001',
                ],
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'phone' => '0770123456',
            'reference' => 'INV-001',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('TK-12345', $result['provider_payment_id']);
    }

    public function test_charge_failure(): void
    {
        Cache::flush();

        Http::fake([
            '*/token*' => Http::response(['access_token' => 'test_token']),
            '*/consumer/v3/c2b/charge' => Http::response([
                'response' => ['responseCode' => '1'],
                'errorMessage' => 'Insufficient balance',
            ], 400),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'phone' => '0770123456',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    // ─── Refund (B2C) ──────────────────────────────────────────────────

    public function test_refund_requires_phone(): void
    {
        $result = $this->provider->refund('TK-12345', 5000);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    public function test_refund_requires_b2c_credentials(): void
    {
        $provider = new TkashProvider(
            consumerKey: 'k',
            consumerSecret: 's',
            consumerId: '600100',
        );

        $result = $provider->refund('TK-12345', 5000, ['phone' => '0770123456']);

        $this->assertFalse($result['success']);
        $error = $result['metadata']['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('b2c_username', $error);
    }

    public function test_refund_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/token*' => Http::response(['access_token' => 'test_token']),
            '*/disbursement/v3/b2c' => Http::response([
                'response' => [
                    'responseCode' => '0',
                    'transactionId' => 'DIS-12345',
                ],
            ]),
        ]);

        $result = $this->provider->refund('TK-12345', 5000, [
            'phone' => '0770123456',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('DIS-12345', $result['provider_refund_id']);
    }

    // ─── Payment Status ────────────────────────────────────────────────

    public function test_get_payment_status_completed(): void
    {
        Cache::flush();

        Http::fake([
            '*/token*' => Http::response(['access_token' => 'test_token']),
            '*/consumer/v3/transactionStatus/*' => Http::response([
                'response' => ['status' => 'SUCCESS'],
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('TK-12345'));
    }

    public function test_get_payment_status_failed(): void
    {
        Cache::flush();

        Http::fake([
            '*/token*' => Http::response(['access_token' => 'test_token']),
            '*/consumer/v3/transactionStatus/*' => Http::response([
                'response' => ['status' => 'FAILED'],
            ]),
        ]);

        $this->assertEquals('failed', $this->provider->getPaymentStatus('TK-12345'));
    }

    // ─── C2B Setup ─────────────────────────────────────────────────────

    public function test_register_urls(): void
    {
        Cache::flush();

        Http::fake([
            '*/token*' => Http::response(['access_token' => 'test_token']),
            '*/consumer/v3/registerurl' => Http::response([
                'response' => ['responseCode' => '0', 'responseDesc' => 'Success'],
            ]),
        ]);

        $result = $this->provider->registerUrls();

        $this->assertArrayHasKey('response', $result);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'consumer/v3/registerurl'));
    }

    // ─── Webhook (C2B confirmation) ────────────────────────────────────

    public function test_verify_webhook_valid(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'confirmationRequest' => [
                'transactionId' => 'TK-12345',
                'status' => 'SUCCESS',
            ],
        ]);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'some_other_data' => 'value',
        ]);

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_completed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'confirmationRequest' => [
                'transactionId' => 'TK-12345',
                'status' => 'SUCCESS',
                'amount' => '500',
                'currency' => 'KES',
                'receiptNumber' => 'TK-RCPT-001',
                'message' => 'Transaction successful',
                'msisdn' => '254770123456',
                'referenceId' => 'INV-001',
            ],
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $event['event']);
        $this->assertEquals('TK-12345', $event['provider_payment_id']);
        $this->assertEquals('completed', $event['status']);
        $this->assertEquals(50000, $event['amount']);
        $this->assertEquals('KES', $event['currency']);
    }

    public function test_parse_webhook_failed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'confirmationRequest' => [
                'transactionId' => 'TK-99999',
                'status' => 'FAILED',
                'amount' => '100',
                'currency' => 'KES',
            ],
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.failed', $event['event']);
        $this->assertEquals('failed', $event['status']);
    }
}
