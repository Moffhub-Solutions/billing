<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class PaymentApiTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected Plan $starterPlan;

    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Feature::create(['slug' => 'gatebook', 'name' => 'Gatebook', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);

        $this->starterPlan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook'],
            'limits' => [],
        ]);

        $this->company = Company::create(['name' => 'Payment Test Co']);
        $this->user = User::create([
            'name' => 'Payment User',
            'email' => 'payment@example.com',
            'company_id' => $this->company->id,
        ]);

        $this->subscription = $this->company->subscriptions()->create([
            'ulid' => Str::ulid()->toBase32(),
            'plan_id' => $this->starterPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);
    }

    // ─── List Payments ──────────────────────────────────────────────────

    public function test_list_payments(): void
    {
        $this->createPayment($this->company, PaymentStatus::COMPLETED);
        $this->createPayment($this->company, PaymentStatus::PENDING);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/payments');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'amount', 'status', 'currency']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_list_payments_filtered_by_status(): void
    {
        $this->createPayment($this->company, PaymentStatus::COMPLETED);
        $this->createPayment($this->company, PaymentStatus::PENDING);
        $this->createPayment($this->company, PaymentStatus::FAILED);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/payments?status=completed');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_list_payments_filtered_by_provider(): void
    {
        $this->createPayment($this->company, PaymentStatus::COMPLETED, 'manual');
        $this->createPayment($this->company, PaymentStatus::COMPLETED, 'manual');

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/payments?provider=manual');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_list_payments_pagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createPayment($this->company, PaymentStatus::COMPLETED);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/payments?per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_list_payments_no_billable(): void
    {
        $userNoBillable = User::create([
            'name' => 'No Company',
            'email' => 'nopayment@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($userNoBillable)
            ->getJson('/api/billing/payments');

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }

    // ─── Initiate Payment ───────────────────────────────────────────────

    public function test_initiate_payment_manual_provider(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments', [
                'amount' => 250000,
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Payment initiated.')
            ->assertJsonPath('data.amount', 250000)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.currency', 'KES');

        $this->assertDatabaseHas('billing_payments', [
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'amount' => 250000,
            'status' => 'pending',
        ]);
    }

    public function test_initiate_payment_with_subscription_id(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments', [
                'amount' => 250000,
                'subscription_id' => $this->subscription->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.subscription_id', $this->subscription->id);
    }

    public function test_initiate_payment_with_invoice_id(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments', [
                'amount' => 250000,
                'invoice_id' => 42,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.invoice_id', 42);
    }

    public function test_initiate_payment_validation_errors(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_initiate_payment_validation_amount_must_be_positive(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments', [
                'amount' => 0,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_initiate_payment_no_billable(): void
    {
        $userNoBillable = User::create([
            'name' => 'No Company',
            'email' => 'nopayment2@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($userNoBillable)
            ->postJson('/api/billing/payments', [
                'amount' => 250000,
            ]);

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }

    // ─── Show Payment ───────────────────────────────────────────────────

    public function test_show_payment(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::COMPLETED);

        $response = $this->getJson("/api/billing/payments/{$payment->id}");

        $response->assertOk()
            ->assertJsonPath('data.amount', 250000)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonStructure([
                'data' => ['id', 'amount', 'status', 'currency', 'payment_provider'],
            ]);
    }

    public function test_show_payment_not_found(): void
    {
        $response = $this->getJson('/api/billing/payments/99999');

        $response->assertNotFound();
    }

    // ─── Refund Payment ─────────────────────────────────────────────────

    public function test_refund_payment(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::COMPLETED);

        $response = $this->postJson("/api/billing/payments/{$payment->id}/refund");

        $response->assertOk()
            ->assertJsonPath('message', 'Payment refunded.')
            ->assertJsonPath('data.status', 'refunded');

        $payment->refresh();
        $this->assertNotNull($payment->refunded_at);
    }

    public function test_refund_payment_not_completed(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::PENDING);

        $response = $this->postJson("/api/billing/payments/{$payment->id}/refund");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Only completed payments can be refunded.');
    }

    public function test_refund_payment_partial(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::COMPLETED);

        $response = $this->postJson("/api/billing/payments/{$payment->id}/refund", [
            'amount' => 100000,
            'reason' => 'Partial refund for unused period',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Payment refunded.')
            ->assertJsonPath('data.status', 'refunded');
    }

    public function test_refund_payment_not_found(): void
    {
        $response = $this->postJson('/api/billing/payments/99999/refund');

        $response->assertNotFound();
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    protected function createPayment(
        Company $company,
        PaymentStatus $status,
        string $provider = 'manual',
    ): Payment {
        return $company->payments()->create([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 250000,
            'currency' => 'KES',
            'status' => $status,
            'payment_provider' => $provider,
            'provider_payment_id' => 'test_'.Str::ulid()->toBase32(),
            'paid_at' => $status === PaymentStatus::COMPLETED ? now() : null,
            'failed_at' => $status === PaymentStatus::FAILED ? now() : null,
        ]);
    }
}
