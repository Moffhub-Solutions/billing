<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
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

    // ─── Payment Options (multi-channel selector) ──────────────────────

    public function test_list_payment_options_defaults_to_configured(): void
    {
        $this->setConfig('billing.providers.mpesa', ['consumer_key' => 'k', 'consumer_secret' => 's']);
        $this->setConfig('billing.providers.airtel', ['client_id' => 'i', 'client_secret' => 's']);
        $this->setConfig('billing.enabled_providers', []);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/payments/options');

        $response->assertOk();
        $data = $response->json('data');
        $providers = is_array($data) ? array_column($data, 'provider') : [];
        $this->assertContains('mpesa', $providers);
        $this->assertContains('airtel', $providers);
        $this->assertContains('manual', $providers);
    }

    public function test_list_payment_options_respects_curated_order(): void
    {
        $this->setConfig('billing.providers.mpesa', ['consumer_key' => 'k', 'consumer_secret' => 's']);
        $this->setConfig('billing.providers.airtel', ['client_id' => 'i', 'client_secret' => 's']);
        $this->setConfig('billing.providers.tkash', ['consumer_key' => 'k', 'consumer_secret' => 's', 'consumer_id' => '600100']);
        $this->setConfig('billing.enabled_providers', ['airtel', 'tkash', 'mpesa']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/payments/options');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.provider', 'airtel')
            ->assertJsonPath('data.0.label', 'Airtel Money')
            ->assertJsonPath('data.0.method', 'airtel_money')
            ->assertJsonPath('data.1.provider', 'tkash')
            ->assertJsonPath('data.1.label', 'T-Kash')
            ->assertJsonPath('data.1.method', 'tkash')
            ->assertJsonPath('data.2.provider', 'mpesa');
    }

    public function test_initiate_payment_rejects_disabled_provider(): void
    {
        $this->setConfig('billing.providers.mpesa', ['consumer_key' => 'k', 'consumer_secret' => 's']);
        $this->setConfig('billing.enabled_providers', ['manual']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments', [
                'amount' => 250000,
                'provider' => 'mpesa',
            ]);

        $response->assertStatus(422);
    }

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

        $response = $this->actingAs($this->user)
            ->getJson("/api/billing/payments/{$payment->id}");

        $response->assertOk()
            ->assertJsonPath('data.amount', 250000)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonStructure([
                'data' => ['id', 'amount', 'status', 'currency', 'payment_provider'],
            ]);
    }

    public function test_show_payment_not_found(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/payments/99999');

        $response->assertNotFound();
    }

    public function test_show_payment_rejects_other_billables_payment(): void
    {
        $otherCompany = Company::create(['name' => 'Other Co']);
        $payment = $this->createPayment($otherCompany, PaymentStatus::COMPLETED);

        // Acting as our user must not expose another billable's payment.
        $response = $this->actingAs($this->user)
            ->getJson("/api/billing/payments/{$payment->id}");

        $response->assertNotFound();
    }

    // ─── Refund Payment ─────────────────────────────────────────────────

    public function test_refund_payment(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::COMPLETED);

        $response = $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$payment->id}/refund");

        $response->assertOk()
            ->assertJsonPath('message', 'Payment refunded.')
            ->assertJsonPath('data.status', 'refunded');

        $payment->refresh();
        $this->assertNotNull($payment->refunded_at);
    }

    public function test_refund_payment_not_completed(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::PENDING);

        $response = $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$payment->id}/refund");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Only completed payments can be refunded.');
    }

    public function test_refund_payment_partial_keeps_payment_completed(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::COMPLETED);

        // Captured 250000; refunding 100000 is partial, so the payment stays
        // COMPLETED (the remainder can still be refunded) and tracks the total.
        $response = $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$payment->id}/refund", [
                'amount' => 100000,
                'reason' => 'Partial refund for unused period',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Payment refunded.')
            ->assertJsonPath('data.status', 'completed');

        $meta = $payment->refresh()->metadata;
        $this->assertIsArray($meta);
        $this->assertSame(100000, $meta['refunded_amount'] ?? null);
    }

    public function test_refund_amount_cannot_exceed_captured(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::COMPLETED);

        // Captured is 250000; asking for more must be rejected and settle nothing.
        $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$payment->id}/refund", ['amount' => 9_999_999])
            ->assertStatus(422);

        $this->assertSame(PaymentStatus::COMPLETED, $payment->refresh()->status);
        $this->assertArrayNotHasKey('refunded_amount', $payment->metadata ?? []);
    }

    public function test_full_refund_marks_payment_refunded(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::COMPLETED);

        // Omitting amount refunds the full remaining captured amount.
        $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$payment->id}/refund")
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        $meta = $payment->refresh()->metadata;
        $this->assertIsArray($meta);
        $this->assertSame(250000, $meta['refunded_amount'] ?? null);
    }

    public function test_partial_refunds_cannot_cumulatively_exceed_captured(): void
    {
        $payment = $this->createPayment($this->company, PaymentStatus::COMPLETED);

        $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$payment->id}/refund", ['amount' => 200000])
            ->assertOk();

        // 200000 already refunded; a further 100000 would exceed the 250000 cap.
        $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$payment->id}/refund", ['amount' => 100000])
            ->assertStatus(422);

        $meta = $payment->refresh()->metadata;
        $this->assertIsArray($meta);
        $this->assertSame(200000, $meta['refunded_amount'] ?? null);
    }

    public function test_refund_payment_not_found(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments/99999/refund');

        $response->assertNotFound();
    }

    public function test_refund_payment_rejects_other_billables_payment(): void
    {
        $otherCompany = Company::create(['name' => 'Other Co']);
        $payment = $this->createPayment($otherCompany, PaymentStatus::COMPLETED);

        // Acting as our user must not be able to refund another billable's payment.
        $response = $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$payment->id}/refund");

        $response->assertNotFound();

        $this->assertNull($payment->refresh()->refunded_at);
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
