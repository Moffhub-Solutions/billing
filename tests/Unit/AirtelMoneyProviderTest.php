<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Providers\AirtelMoneyProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class AirtelMoneyProviderTest extends BaseTestCase
{
    protected AirtelMoneyProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new AirtelMoneyProvider(
            clientId: 'test_client_id',
            clientSecret: 'test_client_secret',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/billing/webhooks/airtel',
            country: 'KE',
            currency: 'KES',
        );
    }

    // ─── Configuration ─────────────────────────────────────────────────

    public function test_get_name(): void
    {
        $this->assertEquals('airtel', $this->provider->getName());
    }

    public function test_is_configured_with_all_credentials(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new AirtelMoneyProvider(
            clientId: '',
            clientSecret: '',
        );

        $this->assertFalse($provider->isConfigured());
    }

    // ─── OAuth Token ───────────────────────────────────────────────────

    public function test_get_access_token(): void
    {
        Cache::flush();

        Http::fake([
            '*/auth/oauth2/token' => Http::response([
                'access_token' => 'airtel_test_token_123',
                'expires_in' => 3600,
                'token_type' => 'bearer',
            ]),
        ]);

        $token = $this->provider->getAccessToken();

        $this->assertEquals('airtel_test_token_123', $token);
        Http::assertSentCount(1);
    }

    public function test_access_token_is_cached(): void
    {
        Cache::flush();

        Http::fake([
            '*/auth/oauth2/token' => Http::response([
                'access_token' => 'airtel_cached_token',
                'expires_in' => 3600,
            ]),
        ]);

        $this->provider->getAccessToken();
        $this->provider->getAccessToken();

        Http::assertSentCount(1);
    }

    // ─── Charge ────────────────────────────────────────────────────────

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
            '*/auth/oauth2/token' => Http::response([
                'access_token' => 'test_token',
            ]),
            '*/merchant/v2/payments/*' => Http::response([
                'status' => [
                    'code' => '200',
                    'message' => 'SUCCESS',
                    'result_code' => 'ESB000010',
                    'success' => true,
                ],
                'data' => [
                    'transaction' => [
                        'id' => 'TXN-12345',
                        'reference_id' => 'REF-12345',
                        'status' => 'TS',
                    ],
                ],
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'phone' => '0733123456',
            'reference' => 'INV-001',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('TXN-12345', $result['provider_payment_id']);
    }

    // ─── Refund ────────────────────────────────────────────────────────

    public function test_refund_requires_phone(): void
    {
        $result = $this->provider->refund('TXN-12345', 5000);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    public function test_refund_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/auth/oauth2/token' => Http::response([
                'access_token' => 'test_token',
            ]),
            '*/standard/v2/disbursements/*' => Http::response([
                'status' => [
                    'code' => '200',
                    'message' => 'SUCCESS',
                    'success' => true,
                ],
                'data' => [
                    'transaction' => [
                        'id' => 'DIS-12345',
                        'status' => 'TS',
                    ],
                ],
            ]),
        ]);

        $result = $this->provider->refund('TXN-12345', 5000, [
            'phone' => '0733123456',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
    }

    // ─── Payment Status ────────────────────────────────────────────────

    public function test_get_payment_status_completed(): void
    {
        Cache::flush();

        Http::fake([
            '*/auth/oauth2/token' => Http::response(['access_token' => 'test_token']),
            '*/standard/v2/payments/*' => Http::response([
                'data' => [
                    'transaction' => [
                        'status' => 'TS',
                    ],
                ],
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('TXN-12345'));
    }

    public function test_get_payment_status_failed(): void
    {
        Cache::flush();

        Http::fake([
            '*/auth/oauth2/token' => Http::response(['access_token' => 'test_token']),
            '*/standard/v2/payments/*' => Http::response([
                'data' => [
                    'transaction' => [
                        'status' => 'TF',
                    ],
                ],
            ]),
        ]);

        $this->assertEquals('failed', $this->provider->getPaymentStatus('TXN-12345'));
    }

    // ─── Webhook ───────────────────────────────────────────────────────

    public function test_verify_webhook_valid(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transaction' => [
                'id' => 'TXN-12345',
                'status_code' => 'TS',
            ],
        ]);

        // Airtel does not sign callbacks: the payload alone is never "verified".
        // Authenticity is established by the controller's async re-query.
        $this->assertFalse($this->provider->verifyWebhook($request));
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
            'transaction' => [
                'id' => 'TXN-12345',
                'status_code' => 'TS',
                'amount' => '500',
                'currency' => 'KES',
                'airtel_money_id' => 'AM-12345',
                'message' => 'Transaction successful',
                'msisdn' => '733123456',
                'reference' => 'INV-001',
            ],
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $event['event']);
        $this->assertEquals('TXN-12345', $event['provider_payment_id']);
        $this->assertEquals('completed', $event['status']);
        $this->assertEquals(50000, $event['amount']);
        $this->assertEquals('KES', $event['currency']);
    }

    public function test_parse_webhook_failed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transaction' => [
                'id' => 'TXN-99999',
                'status_code' => 'TF',
                'amount' => '100',
                'currency' => 'KES',
            ],
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.failed', $event['event']);
        $this->assertEquals('failed', $event['status']);
    }
}
