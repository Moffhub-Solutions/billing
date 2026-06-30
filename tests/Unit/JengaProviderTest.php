<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Providers\JengaProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class JengaProviderTest extends BaseTestCase
{
    protected JengaProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new JengaProvider(
            apiKey: 'test_api_key',
            merchantCode: 'MERCHANT001',
            consumerSecret: 'test_consumer_secret',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/billing/webhooks/jenga',
        );
    }

    // ─── Configuration ─────────────────────────────────────────────────

    public function test_get_name(): void
    {
        $this->assertEquals('jenga', $this->provider->getName());
    }

    public function test_is_configured_with_all_credentials(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new JengaProvider(
            apiKey: '',
            merchantCode: '',
            consumerSecret: '',
        );

        $this->assertFalse($provider->isConfigured());
    }

    // ─── OAuth Token ───────────────────────────────────────────────────

    public function test_get_access_token(): void
    {
        Cache::flush();

        Http::fake([
            '*/authenticate/merchant' => Http::response([
                'accessToken' => 'jenga_test_token_123',
                'expiresIn' => '3600',
            ]),
        ]);

        $token = $this->provider->getAccessToken();

        $this->assertEquals('jenga_test_token_123', $token);
        Http::assertSentCount(1);
    }

    public function test_access_token_is_cached(): void
    {
        Cache::flush();

        Http::fake([
            '*/authenticate/merchant' => Http::response([
                'accessToken' => 'jenga_cached_token',
            ]),
        ]);

        $this->provider->getAccessToken();
        $this->provider->getAccessToken();

        Http::assertSentCount(1);
    }

    // ─── Charge ────────────────────────────────────────────────────────

    public function test_charge_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/authenticate/merchant' => Http::response(['accessToken' => 'test_token']),
            '*/v3-apis/transaction-api/v3.0/remittance' => Http::response([
                'status' => true,
                'transactionId' => 'JENGA-TXN-001',
                'reference' => 'INV-001',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'phone' => '0712345678',
            'reference' => 'INV-001',
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('JENGA-TXN-001', $result['provider_payment_id']);
    }

    public function test_charge_failure(): void
    {
        Cache::flush();

        Http::fake([
            '*/authenticate/merchant' => Http::response(['accessToken' => 'test_token']),
            '*/v3-apis/transaction-api/v3.0/remittance' => Http::response([
                'status' => false,
                'message' => 'Transaction failed',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'phone' => '0712345678',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    // ─── Payment Status ────────────────────────────────────────────────

    public function test_get_payment_status_completed(): void
    {
        Cache::flush();

        Http::fake([
            '*/authenticate/merchant' => Http::response(['accessToken' => 'test_token']),
            '*/v3-apis/transaction-api/v3.0/payments/*' => Http::response([
                'status' => 'completed',
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('JENGA-TXN-001'));
    }

    public function test_get_payment_status_failed(): void
    {
        Cache::flush();

        Http::fake([
            '*/authenticate/merchant' => Http::response(['accessToken' => 'test_token']),
            '*/v3-apis/transaction-api/v3.0/payments/*' => Http::response([
                'status' => 'failed',
            ]),
        ]);

        $this->assertEquals('failed', $this->provider->getPaymentStatus('JENGA-TXN-001'));
    }

    // ─── Webhook ───────────────────────────────────────────────────────

    public function test_verify_webhook_with_transaction_id(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transactionId' => 'JENGA-TXN-001',
            'status' => 'completed',
        ]);

        // A signing key is configured, so an unsigned structure-only payload is
        // rejected (no structure fallback to bypass via a missing signature).
        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'some_other_field' => 'value',
        ]);
        $request->headers->set('X-Jenga-Signature', '');

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_completed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transactionId' => 'JENGA-TXN-001',
            'status' => 'completed',
            'amount' => '500.00',
            'currency' => 'KES',
            'reference' => 'INV-001',
            'type' => 'mobile_money',
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $event['event']);
        $this->assertEquals('JENGA-TXN-001', $event['provider_payment_id']);
        $this->assertEquals('completed', $event['status']);
        $this->assertEquals(50000, $event['amount']);
        $this->assertEquals('KES', $event['currency']);
    }

    public function test_parse_webhook_failed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transactionId' => 'JENGA-TXN-002',
            'status' => 'failed',
            'amount' => '200.00',
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.failed', $event['event']);
        $this->assertEquals('failed', $event['status']);
    }
}
