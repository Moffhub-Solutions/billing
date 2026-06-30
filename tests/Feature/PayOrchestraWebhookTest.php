<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Jobs\ConfirmWebhookPayment;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class PayOrchestraWebhookTest extends BaseTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.providers.payorchestra' => [
                'api_key' => 'po_test_xxx',
                'org_id' => 'org_test',
                'webhook_secret' => 'whsec_test',
                'base_url' => 'https://backbone.test',
            ],
        ]);
    }

    public function test_completed_webhook_marks_payment_paid_and_dispatches_event(): void
    {
        Event::fake([PaymentReceived::class]);

        $company = Company::create(['name' => 'Backbone Co']);
        $payment = $this->createPendingPayment($company, 'pi_123');

        $body = [
            'event' => 'payment_intent.completed',
            'data' => [
                'id' => 'pi_123',
                'reference_code' => 'PO-REF-1',
                'status' => 'completed',
                'amount' => 10000,
                'currency' => 'KES',
                'channel' => 'mpesa',
                'paid_at' => '2026-04-25T10:15:00Z',
                'metadata' => ['invoice_id' => 42],
            ],
        ];

        $this->postSignedWebhook($body)
            ->assertOk()
            ->assertJsonPath('status', 'received');

        $payment->refresh();

        $this->assertSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('PO-REF-1', $payment->provider_reference);
        $metadata = $payment->metadata ?? [];
        $this->assertSame('mpesa', $metadata['channel'] ?? null);

        Event::assertDispatched(
            PaymentReceived::class,
            fn (PaymentReceived $event) => $event->payment->id === $payment->id
                && $event->billable->is($company)
        );
    }

    public function test_webhook_rejects_invalid_signature_when_confirmation_disabled(): void
    {
        // With async re-query confirmation turned off, an unverified callback
        // (here, a bad signature) is rejected outright and settles nothing.
        config(['billing.webhooks.confirm_unverified' => false]);

        $company = Company::create(['name' => 'Backbone Co']);
        $payment = $this->createPendingPayment($company, 'pi_456');

        $body = [
            'event' => 'payment_intent.completed',
            'data' => ['id' => 'pi_456', 'status' => 'completed', 'amount' => 1000, 'currency' => 'KES'],
        ];

        $this->postJson('/billing/webhooks/payorchestra', $body, [
            'X-PayOrchestra-Signature' => 'invalid',
        ])->assertStatus(403);

        $this->assertSame(PaymentStatus::PENDING, $payment->refresh()->status);
    }

    public function test_unverified_webhook_enqueues_async_requery_confirmation(): void
    {
        // Default: an unverified callback for a known pending payment is
        // acknowledged (202) and a re-query confirm job is queued, rather than
        // trusting the payload.
        Queue::fake();

        $company = Company::create(['name' => 'Backbone Co']);
        $this->createPendingPayment($company, 'pi_789');

        $this->postJson('/billing/webhooks/payorchestra', [
            'event' => 'payment_intent.completed',
            'data' => ['id' => 'pi_789', 'status' => 'completed', 'amount' => 1000, 'currency' => 'KES'],
        ], [
            'X-PayOrchestra-Signature' => 'invalid',
        ])->assertStatus(202);

        Queue::assertPushed(ConfirmWebhookPayment::class, fn (ConfirmWebhookPayment $job): bool => $job->providerPaymentId === 'pi_789' && $job->provider === 'payorchestra');
    }

    public function test_webhook_processing_is_idempotent(): void
    {
        Event::fake([PaymentReceived::class]);

        $company = Company::create(['name' => 'Backbone Co']);
        $payment = $this->createPendingPayment($company, 'pi_789');

        $body = [
            'event' => 'payment_intent.completed',
            'data' => [
                'id' => 'pi_789',
                'reference_code' => 'PO-REF-2',
                'status' => 'completed',
                'amount' => 10000,
                'currency' => 'KES',
            ],
        ];

        $this->postSignedWebhook($body)->assertOk();
        $this->postSignedWebhook($body)->assertOk();

        Event::assertDispatchedTimes(PaymentReceived::class, 1);
        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(PaymentStatus::COMPLETED, $fresh->status);
    }

    public function test_pending_status_is_acknowledged_but_not_applied(): void
    {
        Event::fake([PaymentReceived::class]);

        $company = Company::create(['name' => 'Backbone Co']);
        $payment = $this->createPendingPayment($company, 'pi_pending');

        $body = [
            'event' => 'payment_intent.processing',
            'data' => [
                'id' => 'pi_pending',
                'status' => 'processing',
                'amount' => 10000,
                'currency' => 'KES',
            ],
        ];

        $this->postSignedWebhook($body)->assertOk();

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(PaymentStatus::PENDING, $fresh->status);
        Event::assertNotDispatched(PaymentReceived::class);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    protected function postSignedWebhook(array $body): TestResponse
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, 'whsec_test');

        return $this->call(
            'POST',
            '/billing/webhooks/payorchestra',
            [],
            [],
            [],
            [
                'HTTP_X_PAYORCHESTRA_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload,
        );
    }

    public function test_ip_allowlist_rejects_callbacks_from_other_ips(): void
    {
        config(['billing.webhooks.providers.payorchestra.ip_allowlist' => ['10.10.10.10']]);

        $company = Company::create(['name' => 'Backbone Co']);
        $payment = $this->createPendingPayment($company, 'pi_ip');

        // Test requests originate from 127.0.0.1, which is not allowlisted.
        $this->postJson('/billing/webhooks/payorchestra', [
            'event' => 'payment_intent.completed',
            'data' => ['id' => 'pi_ip', 'status' => 'completed'],
        ])->assertStatus(403);

        $this->assertSame(PaymentStatus::PENDING, $payment->refresh()->status);
    }

    public function test_configured_secret_verifies_unsigned_callback_and_settles(): void
    {
        Event::fake([PaymentReceived::class]);
        config(['billing.webhooks.providers.payorchestra.secret' => 'url-secret-xyz']);

        $company = Company::create(['name' => 'Backbone Co']);
        $payment = $this->createPendingPayment($company, 'pi_secret');

        // No valid signature, but the configured shared secret on the URL
        // authenticates the request, so it settles inline.
        $this->postJson('/billing/webhooks/payorchestra?secret=url-secret-xyz', [
            'event' => 'payment_intent.completed',
            'data' => ['id' => 'pi_secret', 'status' => 'completed', 'amount' => 10000, 'currency' => 'KES'],
        ])->assertOk()->assertJsonPath('status', 'received');

        $this->assertSame(PaymentStatus::COMPLETED, $payment->refresh()->status);
    }

    public function test_wrong_secret_does_not_verify(): void
    {
        config([
            'billing.webhooks.providers.payorchestra.secret' => 'url-secret-xyz',
            'billing.webhooks.confirm_unverified' => false,
        ]);

        $company = Company::create(['name' => 'Backbone Co']);
        $payment = $this->createPendingPayment($company, 'pi_bad');

        $this->postJson('/billing/webhooks/payorchestra?secret=wrong', [
            'event' => 'payment_intent.completed',
            'data' => ['id' => 'pi_bad', 'status' => 'completed'],
        ])->assertStatus(403);

        $this->assertSame(PaymentStatus::PENDING, $payment->refresh()->status);
    }

    protected function createPendingPayment(Company $company, string $providerPaymentId): Payment
    {
        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 10000,
            'currency' => 'KES',
            'status' => PaymentStatus::PENDING,
            'payment_provider' => 'payorchestra',
            'provider_payment_id' => $providerPaymentId,
        ]);

        $company->payments()->save($payment);

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);

        return $fresh;
    }
}
