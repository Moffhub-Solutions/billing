<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Providers\CoopBankProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class CoopBankProviderTest extends BaseTestCase
{
    protected CoopBankProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new CoopBankProvider(
            consumerKey: 'test_consumer_key',
            consumerSecret: 'test_consumer_secret',
            accountNumber: '36001873000',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/billing/webhooks/coopbank',
        );
    }

    // ─── Configuration ─────────────────────────────────────────────────

    public function test_get_name(): void
    {
        $this->assertEquals('coopbank', $this->provider->getName());
    }

    public function test_is_configured_with_all_credentials(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new CoopBankProvider(consumerKey: '', consumerSecret: '');

        $this->assertFalse($provider->isConfigured());
    }

    // ─── OAuth Token ───────────────────────────────────────────────────

    public function test_get_access_token(): void
    {
        Cache::flush();

        Http::fake([
            '*/token' => Http::response([
                'access_token' => 'coop_test_token_123',
                'expires_in' => 3600,
            ]),
        ]);

        $token = $this->provider->getAccessToken();

        $this->assertEquals('coop_test_token_123', $token);
        Http::assertSentCount(1);
    }

    public function test_access_token_is_cached(): void
    {
        Cache::flush();

        Http::fake([
            '*/token' => Http::response([
                'access_token' => 'coop_cached_token',
            ]),
        ]);

        $this->provider->getAccessToken();
        $this->provider->getAccessToken();

        Http::assertSentCount(1);
    }

    // ─── Charge ────────────────────────────────────────────────────────

    public function test_charge_pesalink_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'test_token']),
            '*/FundsTransfer/External/A2A/PesaLink' => Http::response([
                'MessageCode' => '0',
                'MessageReference' => 'PAY-001',
                'TransactionReference' => 'COOP-TXN-001',
                'MessageDescription' => 'Full Success',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'destination_account' => '0011547896523',
            'bank_code' => '11',
            'reference' => 'PAY-001',
            'description' => 'Test payment',
            'transfer_type' => 'pesalink',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('COOP-TXN-001', $result['provider_reference']);
    }

    public function test_charge_internal_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'test_token']),
            '*/FundsTransfer/Internal/A2A' => Http::response([
                'MessageCode' => '0',
                'MessageReference' => 'PAY-002',
                'TransactionReference' => 'COOP-INT-001',
            ]),
        ]);

        $result = $this->provider->charge(100000, 'KES', [
            'destination_account' => '36001873001',
            'reference' => 'PAY-002',
            'transfer_type' => 'internal',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_charge_failure(): void
    {
        Cache::flush();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'test_token']),
            '*/FundsTransfer/External/A2A/PesaLink' => Http::response([
                'MessageCode' => '-1',
                'MessageDescription' => 'Insufficient funds',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'destination_account' => '0011547896523',
            'bank_code' => '11',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    // ─── Refund ────────────────────────────────────────────────────────

    public function test_refund_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'test_token']),
            '*/FundsTransfer/Internal/A2A' => Http::response([
                'MessageCode' => '0',
                'MessageReference' => 'REF-001',
            ]),
        ]);

        $result = $this->provider->refund('PAY-001', 25000, [
            'destination_account' => '36001873001',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
    }

    // ─── Payment Status ────────────────────────────────────────────────

    public function test_get_payment_status_completed(): void
    {
        Cache::flush();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'test_token']),
            '*/Enquiry/TransactionStatus' => Http::response([
                'TransactionStatus' => 'completed',
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('PAY-001'));
    }

    public function test_get_payment_status_failed(): void
    {
        Cache::flush();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'test_token']),
            '*/Enquiry/TransactionStatus' => Http::response([
                'TransactionStatus' => 'failed',
            ]),
        ]);

        $this->assertEquals('failed', $this->provider->getPaymentStatus('PAY-001'));
    }

    // ─── Webhook ───────────────────────────────────────────────────────

    public function test_verify_webhook_valid(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'MessageReference' => 'PAY-001',
            'MessageCode' => '0',
        ]);

        // Co-op callbacks are unsigned; the payload alone is never "verified".
        // Authenticity comes from the controller's async re-query / IP allowlist.
        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'random_field' => 'value',
        ]);

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_completed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'MessageReference' => 'PAY-001',
            'MessageCode' => '0',
            'Amount' => '500.00',
            'TransactionCurrency' => 'KES',
            'TransactionReference' => 'COOP-TXN-001',
            'MessageDescription' => 'Full Success',
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $event['event']);
        $this->assertEquals('PAY-001', $event['provider_payment_id']);
        $this->assertEquals('completed', $event['status']);
        $this->assertEquals(50000, $event['amount']);
        $this->assertEquals('KES', $event['currency']);
    }

    public function test_parse_webhook_failed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'MessageReference' => 'PAY-002',
            'MessageCode' => '-1',
            'MessageDescription' => 'Transaction failed',
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.failed', $event['event']);
        $this->assertEquals('failed', $event['status']);
    }

    // ─── Account Balance ───────────────────────────────────────────────

    public function test_get_account_balance(): void
    {
        Cache::flush();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'test_token']),
            '*/Enquiry/AccountBalance' => Http::response([
                'MessageCode' => '0',
                'AccountNumber' => '36001873000',
                'Amount' => '150000.00',
                'Currency' => 'KES',
            ]),
        ]);

        $result = $this->provider->getAccountBalance();

        $this->assertEquals('0', $result['MessageCode']);
        $this->assertEquals('150000.00', $result['Amount']);
    }
}
