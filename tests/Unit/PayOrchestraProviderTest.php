<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\PayOrchestraProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class PayOrchestraProviderTest extends BaseTestCase
{
    protected PayOrchestraProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new PayOrchestraProvider(
            apiKey: 'po_test_xxx',
            orgId: 'org_test',
            webhookSecret: 'whsec_test',
            baseUrl: 'https://backbone.test',
        );
    }

    public function test_get_name(): void
    {
        $this->assertEquals('payorchestra', $this->provider->getName());
    }

    public function test_is_configured(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_is_not_configured_without_credentials(): void
    {
        $provider = new PayOrchestraProvider;
        $this->assertFalse($provider->isConfigured());
    }

    public function test_is_not_configured_without_org_id(): void
    {
        $provider = new PayOrchestraProvider(apiKey: 'po_test', orgId: '');
        $this->assertFalse($provider->isConfigured());
    }

    public function test_charge_creates_payment_intent(): void
    {
        Http::fake([
            'https://backbone.test/api/v1/client/payment-intents' => Http::response([
                'data' => [
                    'id' => 'pi_123',
                    'reference_code' => 'PO-ABC123',
                    'status' => 'pending',
                    'channel' => 'mpesa',
                    'checkout_url' => 'https://pay.test/checkout/abc',
                ],
            ]),
        ]);

        $result = $this->provider->charge(5000, 'KES', [
            'channel' => 'mpesa',
            'phone' => '254712345678',
            'reference' => 'INV-001',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pi_123', $result['provider_payment_id']);
        $this->assertEquals('PO-ABC123', $result['provider_reference']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('mpesa', $result['metadata']['channel']);
        $this->assertEquals('https://pay.test/checkout/abc', $result['metadata']['checkout_url']);

        Http::assertSent(fn ($request) => $request->url() === 'https://backbone.test/api/v1/client/payment-intents'
            && $request['amount'] === 5000
            && $request['currency'] === 'KES'
            && $request['channel'] === 'mpesa'
            && $request['payer_phone'] === '254712345678'
            && $request->hasHeader('Authorization', 'Bearer po_test_xxx')
            && $request->hasHeader('X-Organization-Id', 'org_test'));
    }

    public function test_charge_handles_failure_response(): void
    {
        Http::fake([
            'https://backbone.test/api/v1/client/payment-intents' => Http::response([
                'error' => ['message' => 'Insufficient routing capacity', 'code' => 'no_route'],
            ], 422),
        ]);

        $result = $this->provider->charge(5000, 'KES', ['channel' => 'card']);

        $this->assertFalse($result['success']);
        $this->assertNull($result['provider_payment_id']);
        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('Insufficient routing capacity', $result['metadata']['error']);
        $this->assertEquals('no_route', $result['metadata']['code']);
    }

    public function test_refund_initiates_partial_refund(): void
    {
        Http::fake([
            'https://backbone.test/api/v1/client/payment-intents/pi_123/refund' => Http::response([
                'data' => ['id' => 're_456', 'status' => 'processing'],
            ]),
        ]);

        $result = $this->provider->refund('pi_123', 2500, ['reason' => 'duplicate']);

        $this->assertTrue($result['success']);
        $this->assertEquals('re_456', $result['provider_refund_id']);
        $this->assertEquals('pending', $result['status']);

        Http::assertSent(fn ($request) => $request['amount'] === 2500
            && $request['reason'] === 'duplicate');
    }

    public function test_get_payment_status_maps_settled(): void
    {
        Http::fake([
            'https://backbone.test/api/v1/client/payment-intents/pi_xyz' => Http::response([
                'data' => ['status' => 'settled'],
            ]),
        ]);

        $this->assertEquals('completed', $this->provider->getPaymentStatus('pi_xyz'));
    }

    public function test_get_payment_status_returns_unknown_when_request_fails(): void
    {
        Http::fake([
            'https://backbone.test/api/v1/client/payment-intents/*' => Http::response([], 500),
        ]);

        $this->assertEquals('unknown', $this->provider->getPaymentStatus('pi_missing'));
    }

    public function test_verify_webhook_with_valid_signature(): void
    {
        $payload = '{"event":"payment_intent.completed","data":{"id":"pi_123"}}';
        $signature = hash_hmac('sha256', $payload, 'whsec_test');

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_PAYORCHESTRA_SIGNATURE' => $signature,
        ], $payload);

        $this->assertTrue($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_with_invalid_signature(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_PAYORCHESTRA_SIGNATURE' => 'tampered',
        ], '{"event":"payment_intent.completed"}');

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_verify_webhook_rejects_when_secret_unset(): void
    {
        // Hardened in v1.0: we deliberately do NOT fall back to the API key,
        // because the API key travels in every outbound request and using it
        // as the HMAC secret would let a leaked key forge inbound webhooks.
        $provider = new PayOrchestraProvider(
            apiKey: 'po_test_xxx',
            orgId: 'org_test',
            baseUrl: 'https://backbone.test',
        );

        $payload = '{"event":"payment_intent.completed"}';
        $signature = hash_hmac('sha256', $payload, 'po_test_xxx');

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_PAYORCHESTRA_SIGNATURE' => $signature,
        ], $payload);

        $this->assertFalse($provider->verifyWebhook($request));
    }

    public function test_verify_webhook_rejects_missing_signature(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [], '{}');

        $this->assertFalse($this->provider->verifyWebhook($request));
    }

    public function test_parse_webhook_normalizes_completed_event(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'event' => 'payment_intent.completed',
            'data' => [
                'id' => 'pi_123',
                'reference_code' => 'PO-REF',
                'status' => 'completed',
                'amount' => 5000,
                'currency' => 'KES',
                'channel' => 'mpesa',
                'paid_at' => '2026-04-25T10:15:00Z',
                'metadata' => ['invoice_id' => 42],
            ],
        ]);

        $parsed = $this->provider->parseWebhook($request);

        $this->assertEquals('payment_intent.completed', $parsed['event']);
        $this->assertEquals('pi_123', $parsed['provider_payment_id']);
        $this->assertEquals('completed', $parsed['status']);
        $this->assertEquals(5000, $parsed['amount']);
        $this->assertEquals('KES', $parsed['currency']);
        // The unified WebhookController reads provider_reference from metadata.
        $this->assertEquals('PO-REF', $parsed['metadata']['provider_reference']);
        $this->assertEquals('mpesa', $parsed['metadata']['channel']);
        $this->assertEquals(42, $parsed['metadata']['invoice_id']);
    }

    public function test_parse_webhook_maps_failed_status(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'event' => 'payment_intent.failed',
            'data' => [
                'id' => 'pi_999',
                'status' => 'declined',
                'failure_reason' => 'insufficient_funds',
            ],
        ]);

        $parsed = $this->provider->parseWebhook($request);

        $this->assertEquals('failed', $parsed['status']);
        $this->assertEquals('insufficient_funds', $parsed['metadata']['failure_reason']);
    }

    public function test_create_hosted_session(): void
    {
        Http::fake([
            'https://backbone.test/api/v1/client/hosted-payments/sessions' => Http::response([
                'data' => [
                    'id' => 'sess_abc',
                    'payment_url' => 'https://pay.test/sessions/abc',
                    'expires_at' => '2026-04-25T11:00:00Z',
                ],
            ]),
        ]);

        $result = $this->provider->createHostedSession(7500, 'KES', [
            'description' => 'Invoice #INV-001',
            'success_url' => 'https://app.test/success',
            'cancel_url' => 'https://app.test/cancel',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('sess_abc', $result['session_id']);
        $this->assertEquals('https://pay.test/sessions/abc', $result['session_url']);
    }

    public function test_create_hosted_session_failure(): void
    {
        Http::fake([
            'https://backbone.test/api/v1/client/hosted-payments/sessions' => Http::response([
                'error' => ['message' => 'Org not enabled for hosted checkout'],
            ], 403),
        ]);

        $result = $this->provider->createHostedSession(1000, 'KES');

        $this->assertFalse($result['success']);
        $this->assertNull($result['session_url']);
        $this->assertEquals('Org not enabled for hosted checkout', $result['error'] ?? null);
    }

    public function test_available_channels_filters_inactive_connectors(): void
    {
        Http::fake([
            'https://backbone.test/api/v1/client/connectors/installed' => Http::response([
                'data' => [
                    ['slug' => 'mpesa', 'name' => 'M-Pesa', 'status' => 'active', 'supported_channels' => ['mpesa'], 'health_status' => 'healthy'],
                    ['slug' => 'kcb', 'name' => 'KCB', 'status' => 'inactive', 'supported_channels' => ['bank_transfer']],
                    ['slug' => 'paystack', 'name' => 'Paystack', 'status' => 'active', 'supported_channels' => ['card', 'bank']],
                ],
            ]),
        ]);

        $channels = $this->provider->availableChannels();

        $this->assertCount(2, $channels);
        $this->assertEquals('mpesa', $channels[0]['slug']);
        $this->assertEquals('paystack', $channels[1]['slug']);
        $this->assertEquals(['card', 'bank'], $channels[1]['channels']);
    }

    public function test_settlements_query(): void
    {
        Http::fake([
            'https://backbone.test/api/v1/client/settlements*' => Http::response([
                'data' => [['id' => 'settle_1', 'amount' => 50000]],
            ]),
        ]);

        $result = $this->provider->settlements(['from' => '2026-04-01']);

        $this->assertCount(1, $result);
        $first = $result[0] ?? null;
        $this->assertIsArray($first);
        $this->assertEquals('settle_1', $first['id'] ?? null);
    }

    public function test_payment_manager_creates_payorchestra_driver(): void
    {
        config([
            'billing.providers.payorchestra' => [
                'api_key' => 'po_live',
                'org_id' => 'org_live',
                'webhook_secret' => 'whsec_live',
                'base_url' => 'https://backbone.payorchestra.com',
            ],
        ]);

        $manager = app(PaymentManager::class);
        $driver = $manager->driver('payorchestra');

        $this->assertInstanceOf(PayOrchestraProvider::class, $driver);
        $this->assertTrue($driver->isConfigured());
    }

    public function test_payment_manager_reports_payorchestra_in_available_providers(): void
    {
        $providers = app(PaymentManager::class)->getAvailableProviders();

        $this->assertContains('payorchestra', $providers);
    }

    public function test_is_provider_configured_for_payorchestra(): void
    {
        config([
            'billing.providers.payorchestra' => [
                'api_key' => 'po_live',
                'org_id' => 'org_live',
            ],
        ]);

        $this->assertTrue(app(PaymentManager::class)->isProviderConfigured('payorchestra'));
    }

    public function test_is_provider_configured_returns_false_when_missing_org(): void
    {
        config([
            'billing.providers.payorchestra' => ['api_key' => 'po_live'],
        ]);

        $this->assertFalse(app(PaymentManager::class)->isProviderConfigured('payorchestra'));
    }
}
