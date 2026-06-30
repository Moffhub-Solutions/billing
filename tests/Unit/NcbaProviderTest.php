<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Providers\NcbaProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class NcbaProviderTest extends BaseTestCase
{
    protected NcbaProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new NcbaProvider(
            apiKey: 'test_api_key',
            apiSecret: 'test_api_secret',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/billing/webhooks/ncba',
        );
    }

    // ─── Configuration ─────────────────────────────────────────────────

    public function test_get_name(): void
    {
        $this->assertEquals('ncba', $this->provider->getName());
    }

    public function test_is_configured_with_api_key(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_api_key(): void
    {
        $provider = new NcbaProvider(apiKey: '');

        $this->assertFalse($provider->isConfigured());
    }

    // ─── Charge ────────────────────────────────────────────────────────

    public function test_charge_pesalink_success(): void
    {
        Http::fake([
            '*/payments/transfer' => Http::response([
                'status' => 'success',
                'transaction_id' => 'NCBA-TXN-001',
                'reference' => 'INV-001',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'destination_account' => '0123456789',
            'bank_code' => '07',
            'reference' => 'INV-001',
            'transfer_type' => 'pesalink',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('NCBA-TXN-001', $result['provider_payment_id']);
    }

    public function test_charge_failure(): void
    {
        Http::fake([
            '*/payments/transfer' => Http::response([
                'status' => 'error',
                'message' => 'Transaction failed',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'destination_account' => '0123456789',
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
                'refund_id' => 'NCBA-REF-001',
            ]),
        ]);

        $result = $this->provider->refund('NCBA-TXN-001', 25000, [
            'destination_account' => '0123456789',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
    }

    // ─── Payment Status ────────────────────────────────────────────────

    public function test_get_payment_status_completed(): void
    {
        Http::fake([
            '*/payments/status/*' => Http::response([
                'transaction_status' => 'completed',
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('NCBA-TXN-001'));
    }

    public function test_get_payment_status_failed(): void
    {
        Http::fake([
            '*/payments/status/*' => Http::response([
                'transaction_status' => 'failed',
            ]),
        ]);

        $this->assertEquals('failed', $this->provider->getPaymentStatus('NCBA-TXN-001'));
    }

    // ─── Webhook ───────────────────────────────────────────────────────

    public function test_verify_webhook_with_signature(): void
    {
        $payload = json_encode(['transaction_id' => 'TXN-001']);
        $this->assertIsString($payload);
        $signature = hash_hmac('sha256', $payload, 'test_api_secret');

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_NCBA_SIGNATURE' => $signature,
        ], $payload);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid_signature(): void
    {
        $payload = json_encode(['transaction_id' => 'TXN-001']);
        $this->assertIsString($payload);

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_NCBA_SIGNATURE' => 'invalid',
        ], $payload);

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_structure_fallback(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transaction_id' => 'NCBA-TXN-001',
            'status' => 'completed',
        ]);

        // A signing secret is configured, so an unsigned structure-only payload
        // is rejected (no structure fallback to bypass via a missing header).
        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid_structure(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'random_field' => 'value',
        ]);

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_completed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transaction_id' => 'NCBA-TXN-001',
            'transaction_status' => 'completed',
            'amount' => '500.00',
            'currency' => 'KES',
            'reference' => 'INV-001',
            'customer_name' => 'John Doe',
            'phone' => '254712345678',
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $event['event']);
        $this->assertEquals('NCBA-TXN-001', $event['provider_payment_id']);
        $this->assertEquals('completed', $event['status']);
        $this->assertEquals(50000, $event['amount']);
        $this->assertEquals('KES', $event['currency']);
        $this->assertEquals('John Doe', $event['metadata']['customer_name']);
    }

    public function test_parse_webhook_failed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transaction_id' => 'NCBA-TXN-002',
            'transaction_status' => 'failed',
            'amount' => '200.00',
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.failed', $event['event']);
        $this->assertEquals('failed', $event['status']);
    }
}
