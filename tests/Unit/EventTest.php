<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Events\FeatureAccessDenied;
use Moffhub\Billing\Events\InvoiceGenerated;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Events\PlanChanged;
use Moffhub\Billing\Events\SubscriptionCancelled;
use Moffhub\Billing\Events\SubscriptionCreated;
use Moffhub\Billing\Events\SubscriptionExpired;
use Moffhub\Billing\Events\SubscriptionPaused;
use Moffhub\Billing\Events\SubscriptionRenewed;
use Moffhub\Billing\Events\SubscriptionResumed;
use Moffhub\Billing\Events\UsageLimitApproaching;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    // ── SubscriptionCreated ──────────────────────────────

    public function test_subscription_created_has_all_properties(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $plan = new Plan;
        $trialEndsAt = Carbon::now()->addDays(14);

        $event = new SubscriptionCreated($subscription, $billable, $plan, $trialEndsAt);

        $this->assertSame($subscription, $event->subscription);
        $this->assertSame($billable, $event->billable);
        $this->assertSame($plan, $event->plan);
        $this->assertSame($trialEndsAt, $event->trialEndsAt);
    }

    public function test_subscription_created_trial_ends_at_defaults_to_null(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $plan = new Plan;

        $event = new SubscriptionCreated($subscription, $billable, $plan);

        $this->assertNull($event->trialEndsAt);
    }

    public function test_subscription_created_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(SubscriptionCreated::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── SubscriptionRenewed ──────────────────────────────

    public function test_subscription_renewed_has_all_properties(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $plan = new Plan;
        $start = Carbon::now();
        $end = Carbon::now()->addDays(30);

        $event = new SubscriptionRenewed($subscription, $billable, $plan, $start, $end, 500000, 'KES');

        $this->assertSame($subscription, $event->subscription);
        $this->assertSame($billable, $event->billable);
        $this->assertSame($plan, $event->plan);
        $this->assertSame($start, $event->newPeriodStart);
        $this->assertSame($end, $event->newPeriodEnd);
        $this->assertEquals(500000, $event->paymentAmount);
        $this->assertEquals('KES', $event->currency);
    }

    public function test_subscription_renewed_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(SubscriptionRenewed::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── SubscriptionCancelled ────────────────────────────

    public function test_subscription_cancelled_has_all_properties(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $plan = new Plan;
        $cancelledAt = Carbon::now();
        $gracePeriodEnd = Carbon::now()->addDays(7);

        $event = new SubscriptionCancelled($subscription, $billable, $plan, $cancelledAt, $gracePeriodEnd, true);

        $this->assertSame($subscription, $event->subscription);
        $this->assertSame($billable, $event->billable);
        $this->assertSame($plan, $event->plan);
        $this->assertSame($cancelledAt, $event->cancelledAt);
        $this->assertSame($gracePeriodEnd, $event->gracePeriodEnd);
        $this->assertTrue($event->immediately);
    }

    public function test_subscription_cancelled_defaults(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $plan = new Plan;
        $cancelledAt = Carbon::now();

        $event = new SubscriptionCancelled($subscription, $billable, $plan, $cancelledAt);

        $this->assertNull($event->gracePeriodEnd);
        $this->assertFalse($event->immediately);
    }

    public function test_subscription_cancelled_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(SubscriptionCancelled::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── PlanChanged ──────────────────────────────────────

    public function test_plan_changed_has_all_properties(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $oldPlan = new Plan;
        $oldPlan->name = 'Starter';
        $newPlan = new Plan;
        $newPlan->name = 'Professional';

        $event = new PlanChanged($subscription, $billable, $oldPlan, $newPlan, 15000);

        $this->assertSame($subscription, $event->subscription);
        $this->assertSame($billable, $event->billable);
        $this->assertSame($oldPlan, $event->oldPlan);
        $this->assertSame($newPlan, $event->newPlan);
        $this->assertEquals(15000, $event->prorationAmount);
    }

    public function test_plan_changed_proration_defaults_to_null(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $oldPlan = new Plan;
        $newPlan = new Plan;

        $event = new PlanChanged($subscription, $billable, $oldPlan, $newPlan);

        $this->assertNull($event->prorationAmount);
    }

    public function test_plan_changed_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(PlanChanged::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── PaymentReceived ──────────────────────────────────

    public function test_payment_received_has_all_properties(): void
    {
        $payment = new Payment;
        $billable = $this->createMock(Model::class);

        $event = new PaymentReceived($payment, $billable, 500000, 'KES', 'mpesa', 'REF123');

        $this->assertSame($payment, $event->payment);
        $this->assertSame($billable, $event->billable);
        $this->assertEquals(500000, $event->amount);
        $this->assertEquals('KES', $event->currency);
        $this->assertEquals('mpesa', $event->paymentMethod);
        $this->assertEquals('REF123', $event->providerReference);
    }

    public function test_payment_received_optional_defaults(): void
    {
        $payment = new Payment;
        $billable = $this->createMock(Model::class);

        $event = new PaymentReceived($payment, $billable, 500000, 'KES');

        $this->assertNull($event->paymentMethod);
        $this->assertNull($event->providerReference);
    }

    public function test_payment_received_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(PaymentReceived::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── PaymentFailed ────────────────────────────────────

    public function test_payment_failed_has_all_properties(): void
    {
        $payment = new Payment;
        $billable = $this->createMock(Model::class);

        $event = new PaymentFailed($payment, $billable, 500000, 'KES', 'Insufficient funds', 3);

        $this->assertSame($payment, $event->payment);
        $this->assertSame($billable, $event->billable);
        $this->assertEquals(500000, $event->amount);
        $this->assertEquals('KES', $event->currency);
        $this->assertEquals('Insufficient funds', $event->failureReason);
        $this->assertEquals(3, $event->retryCount);
    }

    public function test_payment_failed_nullable_payment_and_defaults(): void
    {
        $billable = $this->createMock(Model::class);

        $event = new PaymentFailed(null, $billable, 500000, 'KES');

        $this->assertNull($event->payment);
        $this->assertNull($event->failureReason);
        $this->assertEquals(0, $event->retryCount);
    }

    public function test_payment_failed_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(PaymentFailed::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── UsageLimitApproaching ────────────────────────────

    public function test_usage_limit_approaching_has_all_properties(): void
    {
        $billable = $this->createMock(Model::class);

        $event = new UsageLimitApproaching($billable, 'ocr_scanning', 80, 100, 0.8);

        $this->assertSame($billable, $event->billable);
        $this->assertEquals('ocr_scanning', $event->featureSlug);
        $this->assertEquals(80, $event->currentUsage);
        $this->assertEquals(100, $event->limit);
        $this->assertEquals(0.8, $event->percentage);
    }

    public function test_usage_limit_approaching_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(UsageLimitApproaching::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── FeatureAccessDenied ──────────────────────────────

    public function test_feature_access_denied_has_properties(): void
    {
        $billable = $this->createMock(Model::class);

        $event = new FeatureAccessDenied($billable, 'analytics', 'professional');

        $this->assertSame($billable, $event->billable);
        $this->assertEquals('analytics', $event->featureSlug);
        $this->assertEquals('professional', $event->planSlug);
    }

    public function test_feature_access_denied_nullable_billable_and_plan_slug(): void
    {
        $event = new FeatureAccessDenied(null, 'analytics');

        $this->assertNull($event->billable);
        $this->assertNull($event->planSlug);
    }

    public function test_feature_access_denied_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(FeatureAccessDenied::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── InvoiceGenerated (new) ───────────────────────────

    public function test_invoice_generated_has_all_properties(): void
    {
        $invoice = new Invoice;
        $billable = $this->createMock(Model::class);
        $dueDate = Carbon::now()->addDays(30);

        $event = new InvoiceGenerated($invoice, $billable, 'INV-2026-0001', 116000, 'KES', $dueDate);

        $this->assertSame($invoice, $event->invoice);
        $this->assertSame($billable, $event->billable);
        $this->assertEquals('INV-2026-0001', $event->invoiceNumber);
        $this->assertEquals(116000, $event->total);
        $this->assertEquals('KES', $event->currency);
        $this->assertSame($dueDate, $event->dueDate);
    }

    public function test_invoice_generated_due_date_defaults_to_null(): void
    {
        $invoice = new Invoice;
        $billable = $this->createMock(Model::class);

        $event = new InvoiceGenerated($invoice, $billable, 'INV-2026-0001', 116000, 'KES');

        $this->assertNull($event->dueDate);
    }

    public function test_invoice_generated_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(InvoiceGenerated::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── SubscriptionExpired (new) ────────────────────────

    public function test_subscription_expired_has_all_properties(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $plan = new Plan;
        $expiredAt = Carbon::now();

        $event = new SubscriptionExpired($subscription, $billable, $plan, $expiredAt);

        $this->assertSame($subscription, $event->subscription);
        $this->assertSame($billable, $event->billable);
        $this->assertSame($plan, $event->plan);
        $this->assertSame($expiredAt, $event->expiredAt);
    }

    public function test_subscription_expired_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(SubscriptionExpired::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── SubscriptionPaused (new) ─────────────────────────

    public function test_subscription_paused_has_all_properties(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $plan = new Plan;
        $pausedAt = Carbon::now();

        $event = new SubscriptionPaused($subscription, $billable, $plan, $pausedAt);

        $this->assertSame($subscription, $event->subscription);
        $this->assertSame($billable, $event->billable);
        $this->assertSame($plan, $event->plan);
        $this->assertSame($pausedAt, $event->pausedAt);
    }

    public function test_subscription_paused_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(SubscriptionPaused::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }

    // ── SubscriptionResumed (new) ────────────────────────

    public function test_subscription_resumed_has_all_properties(): void
    {
        $subscription = new Subscription;
        $billable = $this->createMock(Model::class);
        $plan = new Plan;
        $resumedAt = Carbon::now();

        $event = new SubscriptionResumed($subscription, $billable, $plan, $resumedAt);

        $this->assertSame($subscription, $event->subscription);
        $this->assertSame($billable, $event->billable);
        $this->assertSame($plan, $event->plan);
        $this->assertSame($resumedAt, $event->resumedAt);
    }

    public function test_subscription_resumed_uses_dispatchable(): void
    {
        $traits = class_uses_recursive(SubscriptionResumed::class);

        $this->assertContains(Dispatchable::class, $traits);
        $this->assertContains(SerializesModels::class, $traits);
    }
}
