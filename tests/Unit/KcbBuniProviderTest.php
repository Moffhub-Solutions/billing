<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Providers\KcbBuniProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class KcbBuniProviderTest extends BaseTestCase
{
    protected KcbBuniProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new KcbBuniProvider(
            apiKey: 'test_api_key',
            apiSecret: 'test_api_secret',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/billing/webhooks/kcb',
            merchantCode: 'MERCHANT001',
        );
    }

    // ─── Configuration ─────────────────────────────────────────────────

    public function test_get_name(): void
    {
        $this->assertEquals('kcb', $this->provider->getName());
    }

    public function test_is_configured_with_all_credentials(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new KcbBuniProvider(apiKey: '', apiSecret: '');

        $this->assertFalse($provider->isConfigured());
    }

    // ─── Charge ────────────────────────────────────────────────────────

    public function test_charge_success(): void
    {
        Http::fake([
            '*/payments/initiate' => Http::response([
                'status' => 'success',
                'transaction_id' => 'KCB-TXN-12345',
                'reference' => 'INV-001',
            ]),
        ]);

        $result = $this->provider->charge(50000, 'KES', [
            'phone' => '0712345678',
            'reference' => 'INV-001',
            'payment_channel' => 'mpesa',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('KCB-TXN-12345', $result['provider_payment_id']);
    }

    public function test_charge_with_bank_channel(): void
    {
        Http::fake([
            '*/payments/initiate' => Http::response([
                'status' => 'success',
                'transaction_id' => 'KCB-BANK-001',
            ]),
        ]);

        $result = $this->provider->charge(100000, 'KES', [
            'account_number' => '1234567890',
            'reference' => 'INV-002',
            'payment_channel' => 'bank',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_charge_failure(): void
    {
        Http::fake([
            '*/payments/initiate' => Http::response([
                'status' => 'error',
                'message' => 'Insufficient funds',
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
                'refund_id' => 'REF-KCB-001',
            ]),
        ]);

        $result = $this->provider->refund('KCB-TXN-12345', 25000);

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

        $this->assertEquals('completed', $this->provider->getPaymentStatus('KCB-TXN-12345'));
    }

    public function test_get_payment_status_failed(): void
    {
        Http::fake([
            '*/payments/status/*' => Http::response([
                'transaction_status' => 'failed',
            ]),
        ]);

        $this->assertEquals('failed', $this->provider->getPaymentStatus('KCB-TXN-12345'));
    }

    // ─── Webhook: Signature Verification ───────────────────────────────

    public function test_verify_webhook_valid_hmac_signature(): void
    {
        $payload = json_encode(['transaction_id' => 'TXN-001', 'status' => 'completed']);
        $signature = hash_hmac('sha256', $payload, 'test_api_secret');

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_KCB_SIGNATURE' => $signature,
        ], $payload);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_invalid_hmac_signature(): void
    {
        $payload = json_encode(['transaction_id' => 'TXN-001']);

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_KCB_SIGNATURE' => 'invalid_signature',
        ], $payload);

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_v2_structure(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'header' => [
                'messageID' => 'MSG-001',
                'channelCode' => 'KCB',
                'timeStamp' => '20260101120000',
            ],
            'requestPayload' => [
                'primaryData' => ['businessKey' => 'BK-001'],
                'additionalData' => [
                    'notificationData' => [
                        'transactionID' => 'FT-001',
                        'transactionAmt' => '500',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_v1_structure(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transactionReference' => 'FT-001',
            'transactionAmount' => '500',
            'customerReference' => 'INV-001',
        ]);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    // ─── Webhook: V2 IPN Parsing (nested format) ──────────────────────

    public function test_parse_webhook_v2_ipn(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'header' => [
                'messageID' => 'MSG-12345',
                'channelCode' => 'KCB',
                'originatorConversationID' => 'CONV-001',
                'timeStamp' => '20260115120000',
            ],
            'requestPayload' => [
                'primaryData' => ['businessKey' => 'ORG-001'],
                'additionalData' => [
                    'notificationData' => [
                        'businessKey' => 'INV-001',
                        'businessKeyType' => 'MSISDN',
                        'debitMSISDN' => '254712345678',
                        'transactionAmt' => '500.00',
                        'transactionID' => 'FT123456789',
                        'transactionDate' => '2026-01-15',
                        'firstName' => 'John',
                        'lastName' => 'Doe',
                        'currency' => 'KES',
                        'narration' => 'Payment for INV-001',
                        'transactionType' => 'PAY',
                        'balance' => '150000.00',
                    ],
                ],
            ],
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $event['event']);
        $this->assertEquals('FT123456789', $event['provider_payment_id']);
        $this->assertEquals('completed', $event['status']);
        $this->assertEquals(50000, $event['amount']);
        $this->assertEquals('KES', $event['currency']);
        $this->assertEquals('MSG-12345', $event['metadata']['message_id']);
        $this->assertEquals('254712345678', $event['metadata']['phone']);
        $this->assertEquals('John', $event['metadata']['first_name']);
        $this->assertEquals('Doe', $event['metadata']['last_name']);
        $this->assertEquals('INV-001', $event['metadata']['customer_reference']);
    }

    // ─── Webhook: V1 IPN Parsing (flat format) ────────────────────────

    public function test_parse_webhook_v1_ipn(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'transactionReference' => 'FT987654321',
            'requestId' => 'REQ-001',
            'channelCode' => 'KCB',
            'timeStamp' => '20260115120000',
            'transactionAmount' => '1000.00',
            'currency' => 'KES',
            'customerReference' => 'INV-002',
            'customerName' => 'Jane Doe',
            'customerMobileNumber' => '254798765432',
            'balance' => '200000.00',
            'narration' => 'Payment for INV-002',
            'creditAccountIdentifier' => 'ACC-001',
            'organizationShortCode' => 'ORG-001',
            'tillNumber' => 'TILL-001',
        ]);

        $event = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $event['event']);
        $this->assertEquals('FT987654321', $event['provider_payment_id']);
        $this->assertEquals('completed', $event['status']);
        $this->assertEquals(100000, $event['amount']);
        $this->assertEquals('KES', $event['currency']);
        $this->assertEquals('REQ-001', $event['metadata']['request_id']);
        $this->assertEquals('254798765432', $event['metadata']['phone']);
        $this->assertEquals('Jane Doe', $event['metadata']['customer_name']);
        $this->assertEquals('INV-002', $event['metadata']['customer_reference']);
        $this->assertEquals('TILL-001', $event['metadata']['till_number']);
    }

    // ─── Validation Endpoint ───────────────────────────────────────────

    public function test_handle_validation_v1_valid(): void
    {
        $request = Request::create('/validation', 'POST', [
            'requestId' => 'REQ-001',
            'customerReference' => 'INV-001',
            'organizationReference' => 'ORG-001',
        ]);

        $result = $this->provider->handleValidation($request, function ($customerRef, $orgRef) {
            return [
                'valid' => true,
                'customer_name' => 'John Doe',
                'amount' => 50000, // 500.00 KES in cents
                'bill_type' => 'FIXED',
            ];
        });

        $this->assertEquals('0', $result['statusCode']);
        $this->assertEquals('John Doe', $result['customerName']);
        $this->assertEquals('500.00', $result['billAmount']);
    }

    public function test_handle_validation_v1_invalid(): void
    {
        $request = Request::create('/validation', 'POST', [
            'requestId' => 'REQ-002',
            'customerReference' => 'UNKNOWN',
            'organizationReference' => 'ORG-001',
        ]);

        $result = $this->provider->handleValidation($request, function ($customerRef, $orgRef) {
            return ['valid' => false];
        });

        $this->assertEquals('1', $result['statusCode']);
    }

    public function test_handle_validation_v2_valid(): void
    {
        $request = Request::create('/validation', 'POST', [
            'header' => [
                'messageID' => 'MSG-001',
            ],
            'requestPayload' => [
                'primaryData' => ['businessKey' => 'ORG-001'],
                'additionalData' => [
                    'queryData' => ['businessKey' => 'INV-001'],
                ],
            ],
        ]);

        $result = $this->provider->handleValidation($request, function ($customerRef, $orgRef) {
            return [
                'valid' => true,
                'customer_name' => 'Jane Doe',
                'amount' => 100000,
            ];
        });

        $this->assertEquals('0', $result['header']['statusCode']);
        $this->assertEquals('Jane Doe', $result['responsePayload']['transactionInfo']['customerName']);
        $this->assertEquals('1000.00', $result['responsePayload']['transactionInfo']['amount']);
    }
}
