<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Providers\StanbicProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class StanbicProviderTest extends BaseTestCase
{
    protected StanbicProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new StanbicProvider(
            apiKey: 'test_api_key',
            apiSecret: 'test_api_secret',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/billing/webhooks/stanbic',
            merchantCode: 'STANBIC001',
        );
    }

    // ─── Configuration ─────────────────────────────────────────────────

    public function test_get_name(): void
    {
        $this->assertEquals('stanbic', $this->provider->getName());
    }

    public function test_is_configured_with_all_credentials(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new StanbicProvider(apiKey: '', apiSecret: '');

        $this->assertFalse($provider->isConfigured());
    }

    // ─── Charge ────────────────────────────────────────────────────────

    public function test_charge_stk_push_success(): void
    {
        Http::fake([
            '*/payments/stk-push' => Http::response([
                'status' => 'success',
                'transaction_id' => 'STB-TXN-001',
                'reference' => 'INV-001',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'phone' => '0712345678',
            'reference' => 'INV-001',
            'payment_method' => 'stk_push',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('STB-TXN-001', $result['provider_payment_id']);
    }

    public function test_charge_mobile_money_success(): void
    {
        Http::fake([
            '*/payments/mobile-money' => Http::response([
                'status' => 'success',
                'transaction_id' => 'STB-MM-001',
            ]),
        ]);

        $result = $this->provider->charge(30000, 'KES', [
            'phone' => '0733123456',
            'payment_method' => 'mobile_money',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_charge_bank_transfer_success(): void
    {
        Http::fake([
            '*/payments/bank-transfer' => Http::response([
                'status' => 'success',
                'transaction_id' => 'STB-BT-001',
            ]),
        ]);

        $result = $this->provider->charge(200000, 'KES', [
            'account_number' => '0123456789',
            'bank_code' => '031',
            'payment_method' => 'bank_transfer',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_charge_failure(): void
    {
        Http::fake([
            '*/payments/stk-push' => Http::response([
                'status' => 'error',
                'message' => 'Transaction declined',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'phone' => '0712345678',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    // ─── Refund ────────────────────────────────────────────────────────

    public function test_refund_success(): void
    {
        Http::fake([
            '*/payments/refund' => Http::response([
                'status' => 'success',
                'refund_id' => 'STB-REF-001',
            ]),
        ]);

        $result = $this->provider->refund('STB-TXN-001', 25000);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('STB-REF-001', $result['provider_refund_id']);
    }

    // ─── Payment Status ────────────────────────────────────────────────

    public function test_get_payment_status_completed(): void
    {
        Http::fake([
            '*/payments/status/*' => Http::response([
                'transaction_status' => 'completed',
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('STB-TXN-001'));
    }

    public function test_get_payment_status_failed(): void
    {
        Http::fake([
            '*/payments/status/*' => Http::response([
                'transaction_status' => 'failed',
            ]),
        ]);

        $this->assertEquals('failed', $this->provider->getPaymentStatus('STB-TXN-001'));
    }

    // ─── Webhook ───────────────────────────────────────────────────────

    public function test_verify_webhook_valid_signature(): void
    {
        $payload = json_encode(['transaction_id' => 'TXN-001', 'status' => 'completed']);
        $this->assertIsString($payload);
        $signature = hash_hmac('sha256', $payload, 'test_api_secret');

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_STANBIC_SIGNATURE' => $signature,
        ], $payload);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid_signature(): void
    {
        $payload = json_encode(['transaction_id' => 'TXN-001']);
        $this->assertIsString($payload);

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_STANBIC_SIGNATURE' => 'invalid',
        ], $payload);

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_completed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transaction_id' => 'STB-TXN-001',
            'transaction_status' => 'completed',
            'amount' => '500',
            'currency' => 'KES',
            'reference' => 'INV-001',
            'payment_method' => 'stk_push',
            'phone_number' => '254712345678',
            'receipt_number' => 'RCP-001',
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $event['event']);
        $this->assertEquals('STB-TXN-001', $event['provider_payment_id']);
        $this->assertEquals('completed', $event['status']);
        $this->assertEquals(50000, $event['amount']);
        $this->assertEquals('KES', $event['currency']);
    }

    public function test_parse_webhook_failed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transaction_id' => 'STB-TXN-002',
            'transaction_status' => 'failed',
            'amount' => '200',
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.failed', $event['event']);
        $this->assertEquals('failed', $event['status']);
    }
}
