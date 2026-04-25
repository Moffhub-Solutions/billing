<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\MpesaProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class MpesaProviderTest extends BaseTestCase
{
    protected MpesaProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new MpesaProvider(
            consumerKey: 'test_consumer_key',
            consumerSecret: 'test_consumer_secret',
            shortcode: '174379',
            passkey: 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
            environment: 'sandbox',
            callbackUrl: 'https://example.com/billing/webhooks/mpesa',
            timeoutUrl: 'https://example.com/billing/webhooks/mpesa/timeout',
        );
    }

    // ─── Configuration ─────────────────────────────────────────────────

    public function test_get_name(): void
    {
        $this->assertEquals('mpesa', $this->provider->getName());
    }

    public function test_is_configured_with_all_credentials(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new MpesaProvider(
            consumerKey: '',
            consumerSecret: '',
            shortcode: '',
            passkey: '',
        );

        $this->assertFalse($provider->isConfigured());
    }

    // ─── OAuth Token ───────────────────────────────────────────────────

    public function test_get_access_token(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response([
                'access_token' => 'test_token_12345',
                'expires_in' => '3599',
            ]),
        ]);

        $token = $this->provider->getAccessToken();

        $this->assertEquals('test_token_12345', $token);

        Http::assertSentCount(1);
    }

    public function test_access_token_is_cached(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response([
                'access_token' => 'cached_token',
                'expires_in' => '3599',
            ]),
        ]);

        $this->provider->getAccessToken();
        $this->provider->getAccessToken();

        // Only 1 HTTP call — second call uses cache
        Http::assertSentCount(1);
    }

    // ─── STK Push ──────────────────────────────────────────────────────

    public function test_stk_push_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => '29115-34620561-1',
                'CheckoutRequestID' => 'ws_CO_191220191020363925',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success.',
            ]),
        ]);

        $result = $this->provider->stkPush(
            phone: '254712345678',
            amount: 100,
            accountReference: 'INV-001',
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('ws_CO_191220191020363925', $result['provider_payment_id']);
        $this->assertEquals('29115-34620561-1', $result['provider_reference']);
        $this->assertEquals('pending', $result['status']);
    }

    public function test_stk_push_failure(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '1',
                'ResponseDescription' => 'Error',
            ]),
        ]);

        $result = $this->provider->stkPush(phone: '254712345678', amount: 100);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
    }

    public function test_charge_calls_stk_push(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'MR-001',
                'CheckoutRequestID' => 'CR-001',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success.',
            ]),
        ]);

        // 500000 cents = KES 5,000
        $result = $this->provider->charge(500000, 'KES', [
            'phone' => '254712345678',
            'account_reference' => 'SUB-001',
            'description' => 'Subscription',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('CR-001', $result['provider_payment_id']);
    }

    public function test_charge_fails_without_phone(): void
    {
        $result = $this->provider->charge(100000, 'KES', []);

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['status']);
        $error = $result['metadata']['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('Phone number', $error);
    }

    public function test_charge_converts_cents_to_whole_kes(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'CheckoutRequestID' => 'CR-002',
                'ResponseCode' => '0',
            ]),
        ]);

        $this->provider->charge(750050, 'KES', ['phone' => '254712345678']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            // 750050 cents = KES 7500.50 → rounds up to 7501
            return ($body['Amount'] ?? null) === 7501;
        });
    }

    // ─── STK Query ─────────────────────────────────────────────────────

    public function test_stk_query(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResultCode' => '0',
                'ResultDesc' => 'The service request is processed successfully.',
            ]),
        ]);

        $result = $this->provider->stkQuery('ws_CO_test');

        $this->assertEquals('0', $result['ResultCode']);
    }

    public function test_get_payment_status_completed(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResultCode' => '0',
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('ws_CO_test'));
    }

    public function test_get_payment_status_cancelled(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResultCode' => '1032',
            ]),
        ]);

        $this->assertEquals('cancelled', $this->provider->getPaymentStatus('ws_CO_test'));
    }

    // ─── C2B URL Registration ──────────────────────────────────────────

    public function test_register_c2b_urls(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/c2b/v1/registerurl' => Http::response([
                'ResponseDescription' => 'Success',
            ]),
        ]);

        $result = $this->provider->registerC2bUrls(
            'https://example.com/c2b/confirm',
            'https://example.com/c2b/validate',
        );

        $this->assertEquals('Success', $result['ResponseDescription']);
    }

    // ─── B2C (Refund) ──────────────────────────────────────────────────

    public function test_refund_fails_without_phone(): void
    {
        $result = $this->provider->refund('TRANS123', 10000, []);

        $this->assertFalse($result['success']);
        $error = $result['metadata']['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('Phone number', $error);
    }

    public function test_b2c_fails_without_initiator(): void
    {
        // Default provider has no initiator credentials
        $result = $this->provider->b2c('254712345678', 100);

        $this->assertFalse($result['success']);
        $error = $result['metadata']['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('initiator', $error);
    }

    // ─── Phone Formatting ──────────────────────────────────────────────

    public function test_phone_formatting(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response(['ResponseCode' => '0', 'CheckoutRequestID' => 'CR']),
        ]);

        // Test 0712345678 → 254712345678
        $this->provider->charge(100, 'KES', ['phone' => '0712345678']);

        Http::assertSent(fn ($request) => ($request->data()['PhoneNumber'] ?? null) === '254712345678');
    }

    public function test_phone_formatting_short(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response(['ResponseCode' => '0', 'CheckoutRequestID' => 'CR']),
        ]);

        // Test 712345678 → 254712345678
        $this->provider->charge(100, 'KES', ['phone' => '712345678']);

        Http::assertSent(fn ($request) => ($request->data()['PhoneNumber'] ?? null) === '254712345678');
    }

    // ─── Webhook Parsing ───────────────────────────────────────────────

    public function test_parse_stk_push_success_callback(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [], (string) json_encode([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'MR-001',
                    'CheckoutRequestID' => 'CR-001',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 100.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                            ['Name' => 'TransactionDate', 'Value' => 20260320143000],
                        ],
                    ],
                ],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $parsed['event']);
        $this->assertEquals('CR-001', $parsed['provider_payment_id']);
        $this->assertEquals('completed', $parsed['status']);
        $this->assertEquals(10000, $parsed['amount']); // 100.00 KES → 10000 cents
        $this->assertEquals('KES', $parsed['currency']);
        $this->assertEquals('NLJ7RT61SV', $parsed['metadata']['mpesa_receipt']);
    }

    public function test_parse_stk_push_failure_callback(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [], (string) json_encode([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'MR-001',
                    'CheckoutRequestID' => 'CR-001',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.failed', $parsed['event']);
        $this->assertEquals('failed', $parsed['status']);
        $this->assertEquals(1032, $parsed['metadata']['result_code']);
    }

    public function test_parse_c2b_callback(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'PO23ERTG76U',
            'TransTime' => '20260320145023',
            'TransAmount' => '510.00',
            'BusinessShortCode' => '174379',
            'BillRefNumber' => 'INV-2026-0001',
            'MSISDN' => '254712345678',
            'FirstName' => 'John',
            'LastName' => 'Doe',
        ]);

        $parsed = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $parsed['event']);
        $this->assertEquals('PO23ERTG76U', $parsed['provider_payment_id']);
        $this->assertEquals(51000, $parsed['amount']); // 510.00 → 51000 cents
        $this->assertEquals('INV-2026-0001', $parsed['metadata']['bill_ref_number']);
        $this->assertEquals('John', $parsed['metadata']['first_name']);
    }

    public function test_parse_b2c_result_callback(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [], (string) json_encode([
            'Result' => [
                'ResultType' => 0,
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
                'ConversationID' => 'AG_20260320_00004e48cf7e3533',
                'TransactionID' => 'NLJ41HAY6Q',
                'ResultParameters' => [
                    'ResultParameter' => [
                        ['Key' => 'TransactionAmount', 'Value' => 100],
                        ['Key' => 'TransactionReceipt', 'Value' => 'NLJ41HAY6Q'],
                    ],
                ],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseWebhook($request);

        $this->assertEquals('payment.completed', $parsed['event']);
        $this->assertEquals('AG_20260320_00004e48cf7e3533', $parsed['provider_payment_id']);
        $this->assertEquals(10000, $parsed['amount']);
        $this->assertEquals('NLJ41HAY6Q', $parsed['metadata']['receipt']);
    }

    public function test_verify_webhook_with_stk_callback(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [], (string) json_encode([
            'Body' => ['stkCallback' => ['ResultCode' => 0]],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_with_empty_body_fails(): void
    {
        $request = Request::create('/webhook', 'POST', []);

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    // ─── PaymentManager Integration ────────────────────────────────────

    public function test_payment_manager_creates_mpesa_driver(): void
    {
        config([
            'billing.providers.mpesa.consumer_key' => 'test_key',
            'billing.providers.mpesa.consumer_secret' => 'test_secret',
            'billing.providers.mpesa.shortcode' => '174379',
            'billing.providers.mpesa.passkey' => 'test_passkey',
        ]);

        $manager = app(PaymentManager::class);
        $driver = $manager->driver('mpesa');

        $this->assertInstanceOf(MpesaProvider::class, $driver);
        $this->assertEquals('mpesa', $driver->getName());
        $this->assertTrue($driver->isConfigured());
    }

    // ─── C2B Simulate ──────────────────────────────────────────────────

    public function test_simulate_c2b_success(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'test_token']),
            '*/mpesa/c2b/v1/simulate' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'Accept the service request successfully.',
            ]),
        ]);

        $result = $this->provider->simulateC2b(
            phone: '0712345678',
            amount: 500,
            billRefNumber: 'INV-001',
        );

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'c2b/v1/simulate')
                && $request['Amount'] === 500
                && $request['BillRefNumber'] === 'INV-001';
        });
    }

    public function test_simulate_c2b_buy_goods(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'test_token']),
            '*/mpesa/c2b/v1/simulate' => Http::response([
                'ResponseCode' => '0',
            ]),
        ]);

        $result = $this->provider->simulateC2b(
            phone: '0712345678',
            amount: 1000,
            commandId: 'CustomerBuyGoodsOnline',
        );

        $this->assertTrue($result['success']);
    }

    // ─── C2B Validation Handler ────────────────────────────────────────

    public function test_handle_c2b_validation_accepted(): void
    {
        $request = Request::create('/validation', 'POST', [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'RKTQDM7W6S',
            'TransTime' => '20191122063845',
            'TransAmount' => '10',
            'BusinessShortCode' => '600638',
            'BillRefNumber' => 'INV-001',
            'MSISDN' => '254708374149',
            'FirstName' => 'John',
            'LastName' => 'Doe',
        ]);

        $response = $this->provider->handleC2bValidation($request, function (array $data) {
            return $data['BillRefNumber'] === 'INV-001';
        });

        $this->assertEquals('0', $response['ResultCode']);
        $this->assertEquals('Accepted', $response['ResultDesc']);
    }

    public function test_handle_c2b_validation_rejected(): void
    {
        $request = Request::create('/validation', 'POST', [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'RKTQDM7W6S',
            'BillRefNumber' => 'UNKNOWN',
            'MSISDN' => '254708374149',
        ]);

        $response = $this->provider->handleC2bValidation($request, function (array $data) {
            return false; // reject everything
        });

        $this->assertEquals('C2B00012', $response['ResultCode']);
        $this->assertEquals('Rejected', $response['ResultDesc']);
    }
}
