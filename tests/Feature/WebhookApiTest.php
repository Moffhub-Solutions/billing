<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class WebhookApiTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the PaymentManager so webhook drivers verify and parse successfully
        $this->mockPaymentManager();
    }

    // ─── Webhook Endpoints ──────────────────────────────────────────────

    public function test_mpesa_webhook_receives_and_returns_200(): void
    {
        $response = $this->postJson('/billing/webhooks/mpesa', [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'ABC123',
            'TransAmount' => '1000',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'received');
    }

    public function test_paystack_webhook(): void
    {
        $response = $this->postJson('/billing/webhooks/paystack', [
            'event' => 'charge.success',
            'data' => ['reference' => 'ref_123'],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'received');
    }

    public function test_flutterwave_webhook(): void
    {
        $response = $this->postJson('/billing/webhooks/flutterwave', [
            'event' => 'charge.completed',
            'data' => ['id' => 'flw_123'],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'received');
    }

    public function test_pesapal_webhook(): void
    {
        $response = $this->postJson('/billing/webhooks/pesapal', [
            'pesapal_transaction_tracking_id' => 'track_123',
            'pesapal_notification_type' => 'CHANGE',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'received');
    }

    // ─── Unauthenticated Access ─────────────────────────────────────────

    public function test_webhook_routes_are_unauthenticated(): void
    {
        // These requests have no auth headers and should still work
        $providers = ['mpesa', 'paystack', 'flutterwave', 'pesapal'];

        foreach ($providers as $provider) {
            $response = $this->postJson("/billing/webhooks/{$provider}", ['test' => true]);

            $this->assertTrue(
                $response->status() === 200,
                "Webhook for {$provider} should be accessible without authentication, got status {$response->status()}"
            );
        }
    }

    // ─── Webhook Rate Limiting ──────────────────────────────────────────

    public function test_webhook_rate_limiting_is_configured(): void
    {
        // Verify the billing-webhooks rate limiter is registered
        $limiter = $this->app->make(Limit::class, [
            'maxAttempts' => 0,
        ]);

        // The rate limiter named 'billing-webhooks' should exist
        $rateLimiter = RateLimiter::limiter('billing-webhooks');
        $this->assertNotNull($rateLimiter, 'The billing-webhooks rate limiter should be registered.');

        // Verify it returns a Limit with the configured max attempts
        $request = Request::create('/billing/webhooks/mpesa', 'POST');
        $limit = call_user_func($rateLimiter, $request);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertEquals(
            (int) config('billing.webhooks.rate_limit', 60),
            $limit->maxAttempts
        );
    }

    // ─── Webhook Event Processing ───────────────────────────────────────

    public function test_completed_webhook_marks_payment_paid_and_dispatches_event(): void
    {
        Event::fake([PaymentReceived::class]);

        $company = Company::create(['name' => 'Hook Co']);
        $payment = $this->createPendingPayment($company, 'test_123');

        $this->mockParsedEvent([
            'event' => 'payment.completed',
            'provider_payment_id' => 'test_123',
            'amount' => 10000,
            'currency' => 'KES',
            'status' => 'completed',
            'metadata' => ['provider_reference' => 'mpesa-ref-1'],
        ]);

        $this->postJson('/billing/webhooks/mpesa', ['ok' => true])
            ->assertOk()
            ->assertJsonPath('status', 'received');

        $payment->refresh();

        $this->assertSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('mpesa-ref-1', $payment->provider_reference);

        Event::assertDispatched(
            PaymentReceived::class,
            fn (PaymentReceived $event) => $event->payment->id === $payment->id
                && $event->billable->is($company)
        );
    }

    public function test_failed_webhook_marks_payment_failed_and_dispatches_event(): void
    {
        Event::fake([PaymentFailed::class]);

        $company = Company::create(['name' => 'Hook Co']);
        $payment = $this->createPendingPayment($company, 'test_456');

        $this->mockParsedEvent([
            'event' => 'payment.failed',
            'provider_payment_id' => 'test_456',
            'amount' => 10000,
            'currency' => 'KES',
            'status' => 'failed',
            'metadata' => ['failure_reason' => 'insufficient_funds'],
        ]);

        $this->postJson('/billing/webhooks/mpesa', ['ok' => true])->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::FAILED, $payment->status);
        $this->assertNotNull($payment->failed_at);

        Event::assertDispatched(
            PaymentFailed::class,
            fn (PaymentFailed $event) => $event->payment?->id === $payment->id
                && $event->failureReason === 'insufficient_funds'
        );
    }

    public function test_webhook_processing_is_idempotent_for_replayed_callbacks(): void
    {
        // M-Pesa, Pesapal and KCB all retry callbacks. The second delivery
        // hits an already-completed payment row and must not re-fire events.
        Event::fake([PaymentReceived::class]);

        $company = Company::create(['name' => 'Hook Co']);
        $payment = $this->createPendingPayment($company, 'test_789');

        $this->mockParsedEvent([
            'event' => 'payment.completed',
            'provider_payment_id' => 'test_789',
            'amount' => 10000,
            'currency' => 'KES',
            'status' => 'completed',
            'metadata' => [],
        ]);

        $this->postJson('/billing/webhooks/mpesa', ['ok' => true])->assertOk();
        $this->postJson('/billing/webhooks/mpesa', ['ok' => true])->assertOk();

        Event::assertDispatchedTimes(PaymentReceived::class, 1);
    }

    public function test_webhook_with_unknown_provider_payment_id_returns_200_without_dispatch(): void
    {
        Event::fake([PaymentReceived::class, PaymentFailed::class]);

        $this->mockParsedEvent([
            'event' => 'payment.completed',
            'provider_payment_id' => 'does_not_exist',
            'amount' => 10000,
            'currency' => 'KES',
            'status' => 'completed',
            'metadata' => [],
        ]);

        // 200 so the provider doesn't keep retrying — the row simply doesn't
        // exist on our side, which is logged as a warning, not an error.
        $this->postJson('/billing/webhooks/mpesa', ['ok' => true])->assertOk();

        Event::assertNotDispatched(PaymentReceived::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_webhook_with_non_terminal_status_does_not_update_payment(): void
    {
        // Some providers send "processing" or "pending" callbacks before
        // delivering the final state. These should be acknowledged but
        // leave the payment row untouched.
        Event::fake([PaymentReceived::class, PaymentFailed::class]);

        $company = Company::create(['name' => 'Hook Co']);
        $payment = $this->createPendingPayment($company, 'test_pending');

        $this->mockParsedEvent([
            'event' => 'payment.processing',
            'provider_payment_id' => 'test_pending',
            'amount' => 10000,
            'currency' => 'KES',
            'status' => 'processing',
            'metadata' => [],
        ]);

        $this->postJson('/billing/webhooks/mpesa', ['ok' => true])->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::PENDING, $payment->status);
        $this->assertNull($payment->paid_at);

        Event::assertNotDispatched(PaymentReceived::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    protected function mockPaymentManager(): void
    {
        $mockDriver = $this->createMock(PaymentProviderInterface::class);
        $mockDriver->method('verifyWebhook')->willReturn(true);
        $mockDriver->method('parseWebhook')->willReturn([
            'event' => 'payment.completed',
            'provider_payment_id' => 'test_123',
            'amount' => 10000,
            'currency' => 'KES',
            'status' => 'completed',
            'metadata' => [],
        ]);

        $mockManager = $this->createMock(PaymentManager::class);
        $mockManager->method('driver')->willReturn($mockDriver);

        $this->app->instance(PaymentManager::class, $mockManager);
    }

    /**
     * Override the default mock so each test can dictate the parsed event.
     *
     * @param  array{event: string, provider_payment_id: string|null, status: string, amount: int|null, currency: string|null, metadata: array<string, mixed>}  $event
     */
    protected function mockParsedEvent(array $event): void
    {
        $mockDriver = $this->createMock(PaymentProviderInterface::class);
        $mockDriver->method('verifyWebhook')->willReturn(true);
        $mockDriver->method('parseWebhook')->willReturn($event);

        $mockManager = $this->createMock(PaymentManager::class);
        $mockManager->method('driver')->willReturn($mockDriver);

        $this->app->instance(PaymentManager::class, $mockManager);
    }

    protected function createPendingPayment(Company $company, string $providerPaymentId): Payment
    {
        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 10000,
            'currency' => 'KES',
            'status' => PaymentStatus::PENDING,
            'payment_provider' => 'mpesa',
            'provider_payment_id' => $providerPaymentId,
        ]);

        $company->payments()->save($payment);

        return $payment->fresh();
    }
}
