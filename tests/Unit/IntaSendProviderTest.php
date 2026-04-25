<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Providers\IntaSendProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class IntaSendProviderTest extends BaseTestCase
{
    protected IntaSendProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new IntaSendProvider(
            publishableKey: 'ISPubKey_test_12345',
            secretKey: 'ISSecretKey_test_12345',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/billing/webhooks/intasend',
        );
    }

    // ─── Configuration ─────────────────────────────────────────────────

    public function test_get_name(): void
    {
        $this->assertEquals('intasend', $this->provider->getName());
    }

    public function test_is_configured_with_all_credentials(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new IntaSendProvider(publishableKey: '', secretKey: '');

        $this->assertFalse($provider->isConfigured());
    }

    // ─── Checkout ──────────────────────────────────────────────────────

    public function test_checkout_success(): void
    {
        Http::fake([
            '*/api/v1/checkout/*' => Http::response([
                'id' => 'chk_12345',
                'invoice_id' => 'INV-IS-001',
                'url' => 'https://sandbox.intasend.com/checkout/chk_12345/',
                'api_ref' => 'INV-001',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'reference' => 'INV-001',
            'email' => 'john@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'redirect_url' => 'https://example.com/success',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('chk_12345', $result['provider_payment_id']);
    }

    public function test_checkout_failure(): void
    {
        Http::fake([
            '*/api/v1/checkout/*' => Http::response([
                'error' => 'Invalid request',
            ], 400),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'reference' => 'INV-001',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    // ─── STK Push ──────────────────────────────────────────────────────

    public function test_stk_push_requires_phone(): void
    {
        $result = $this->provider->charge(50000, 'KES', [
            'method' => 'stk_push',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
        $error = $result['metadata']['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('Phone number is required', $error);
    }

    public function test_stk_push_success(): void
    {
        Http::fake([
            '*/api/v1/payment/mpesa-stk-push/*' => Http::response([
                'invoice' => [
                    'invoice_id' => 'INV-IS-002',
                    'state' => 'PENDING',
                    'api_ref' => 'INV-002',
                ],
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'method' => 'stk_push',
            'phone' => '0712345678',
            'reference' => 'INV-002',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('INV-IS-002', $result['provider_payment_id']);
    }

    public function test_stk_push_failure(): void
    {
        Http::fake([
            '*/api/v1/payment/mpesa-stk-push/*' => Http::response([
                'invoice' => [
                    'invoice_id' => 'INV-IS-003',
                    'state' => 'FAILED',
                    'failed_reason' => 'Insufficient balance',
                ],
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'method' => 'stk_push',
            'phone' => '0712345678',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    // ─── Refund / Send Money ───────────────────────────────────────────

    public function test_refund_requires_phone(): void
    {
        $result = $this->provider->refund('INV-IS-001', 25000);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    public function test_refund_success(): void
    {
        Http::fake([
            '*/api/v1/send-money/mpesa/*' => Http::response([
                'tracking_id' => 'TRK-001',
                'status' => 'Preview',
            ]),
        ]);

        $result = $this->provider->refund('INV-IS-001', 25000, [
            'phone' => '0712345678',
            'narrative' => 'Refund for cancelled order',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('TRK-001', $result['provider_refund_id']);
    }

    // ─── Payment Status ────────────────────────────────────────────────

    public function test_get_payment_status_completed(): void
    {
        Http::fake([
            '*/api/v1/payment/status/*' => Http::response([
                'invoice' => [
                    'state' => 'COMPLETE',
                    'invoice_id' => 'INV-IS-001',
                ],
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('INV-IS-001'));
    }

    public function test_get_payment_status_failed(): void
    {
        Http::fake([
            '*/api/v1/payment/status/*' => Http::response([
                'invoice' => [
                    'state' => 'FAILED',
                ],
            ]),
        ]);

        $this->assertEquals('failed', $this->provider->getPaymentStatus('INV-IS-001'));
    }

    public function test_get_payment_status_pending(): void
    {
        Http::fake([
            '*/api/v1/payment/status/*' => Http::response([
                'invoice' => [
                    'state' => 'PENDING',
                ],
            ]),
        ]);

        $this->assertEquals('pending', $this->provider->getPaymentStatus('INV-IS-001'));
    }

    // ─── Webhook ───────────────────────────────────────────────────────

    public function test_verify_webhook_with_signature(): void
    {
        $payload = json_encode(['invoice_id' => 'INV-001']);
        $this->assertIsString($payload);
        $signature = hash_hmac('sha256', $payload, 'ISSecretKey_test_12345');

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_INTASEND_SIGNATURE' => $signature,
        ], $payload);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid_signature(): void
    {
        $payload = json_encode(['invoice_id' => 'INV-001']);
        $this->assertIsString($payload);

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_INTASEND_SIGNATURE' => 'invalid',
        ], $payload);

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_structure_fallback(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'invoice_id' => 'INV-001',
        ]);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_completed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'invoice' => [
                'invoice_id' => 'INV-IS-001',
                'state' => 'COMPLETE',
                'net_amount' => '500.00',
                'currency' => 'KES',
                'api_ref' => 'INV-001',
                'mpesa_reference' => 'QJI3E4R5T6',
                'account' => '254712345678',
            ],
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $event['event']);
        $this->assertEquals('INV-IS-001', $event['provider_payment_id']);
        $this->assertEquals('completed', $event['status']);
        $this->assertEquals(50000, $event['amount']);
        $this->assertEquals('KES', $event['currency']);
        $this->assertEquals('QJI3E4R5T6', $event['metadata']['mpesa_reference']);
    }

    public function test_parse_webhook_failed(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'invoice' => [
                'invoice_id' => 'INV-IS-002',
                'state' => 'FAILED',
                'amount' => '200.00',
                'currency' => 'KES',
                'failed_reason' => 'Insufficient balance',
            ],
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.failed', $event['event']);
        $this->assertEquals('failed', $event['status']);
        $this->assertEquals('Insufficient balance', $event['metadata']['failed_reason']);
    }

    // ─── Status Code Mapping ───────────────────────────────────────────

    public function test_status_code_mapping_via_webhook(): void
    {
        $codes = [
            'TS100' => 'completed',
            'COMPLETE' => 'completed',
            'TF106' => 'failed',
            'FAILED' => 'failed',
            'TC108' => 'cancelled',
        ];

        foreach ($codes as $code => $expected) {
            $request = Request::create('/webhook', 'POST', [
                'invoice_id' => 'INV-TEST',
                'status_code' => $code,
            ]);

            $event = $this->provider->parseWebhook($request);
            $this->assertEquals($expected, $event['status'], "Status code {$code} should map to {$expected}");
        }
    }
}
