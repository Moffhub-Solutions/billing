<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Tests\BaseTestCase;

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
}
