<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Foundation\Events\Dispatchable;
use Moffhub\Billing\Events\FeatureAccessDenied;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Events\PlanChanged;
use Moffhub\Billing\Events\SubscriptionCancelled;
use Moffhub\Billing\Events\SubscriptionCreated;
use Moffhub\Billing\Events\SubscriptionRenewed;
use Moffhub\Billing\Events\UsageLimitApproaching;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function test_subscription_created_has_subscription(): void
    {
        $subscription = new Subscription;
        $event = new SubscriptionCreated($subscription);

        $this->assertSame($subscription, $event->subscription);
    }

    public function test_subscription_created_uses_dispatchable(): void
    {
        $this->assertContains(
            Dispatchable::class,
            class_uses_recursive(SubscriptionCreated::class),
        );
    }

    public function test_subscription_cancelled_has_properties(): void
    {
        $subscription = new Subscription;
        $event = new SubscriptionCancelled($subscription, true);

        $this->assertSame($subscription, $event->subscription);
        $this->assertTrue($event->immediately);
    }

    public function test_subscription_cancelled_default_not_immediately(): void
    {
        $subscription = new Subscription;
        $event = new SubscriptionCancelled($subscription);

        $this->assertFalse($event->immediately);
    }

    public function test_subscription_cancelled_uses_dispatchable(): void
    {
        $this->assertContains(
            Dispatchable::class,
            class_uses_recursive(SubscriptionCancelled::class),
        );
    }

    public function test_plan_changed_has_old_and_new_plan(): void
    {
        $subscription = new Subscription;
        $oldPlan = new Plan;
        $oldPlan->name = 'Starter';
        $newPlan = new Plan;
        $newPlan->name = 'Professional';

        $event = new PlanChanged($subscription, $oldPlan, $newPlan);

        $this->assertSame($subscription, $event->subscription);
        $this->assertSame($oldPlan, $event->oldPlan);
        $this->assertSame($newPlan, $event->newPlan);
        $this->assertEquals('Starter', $event->oldPlan->name);
        $this->assertEquals('Professional', $event->newPlan->name);
    }

    public function test_plan_changed_uses_dispatchable(): void
    {
        $this->assertContains(
            Dispatchable::class,
            class_uses_recursive(PlanChanged::class),
        );
    }

    public function test_payment_received_has_payment(): void
    {
        $payment = new Payment;
        $event = new PaymentReceived($payment);

        $this->assertSame($payment, $event->payment);
    }

    public function test_payment_received_uses_dispatchable(): void
    {
        $this->assertContains(
            Dispatchable::class,
            class_uses_recursive(PaymentReceived::class),
        );
    }

    public function test_payment_failed_has_properties(): void
    {
        $payment = new Payment;
        $event = new PaymentFailed($payment, 'Insufficient funds');

        $this->assertSame($payment, $event->payment);
        $this->assertEquals('Insufficient funds', $event->reason);
    }

    public function test_payment_failed_default_null_reason(): void
    {
        $payment = new Payment;
        $event = new PaymentFailed($payment);

        $this->assertNull($event->reason);
    }

    public function test_payment_failed_uses_dispatchable(): void
    {
        $this->assertContains(
            Dispatchable::class,
            class_uses_recursive(PaymentFailed::class),
        );
    }

    public function test_usage_limit_approaching_has_all_properties(): void
    {
        $billable = new \stdClass;
        // Use a mock for the model since we just need to test property assignment
        $billable = $this->createMock(\Illuminate\Database\Eloquent\Model::class);

        $event = new UsageLimitApproaching($billable, 'ocr_scanning', 80, 100, 0.8);

        $this->assertSame($billable, $event->billable);
        $this->assertEquals('ocr_scanning', $event->featureSlug);
        $this->assertEquals(80, $event->currentUsage);
        $this->assertEquals(100, $event->limit);
        $this->assertEquals(0.8, $event->percentage);
    }

    public function test_usage_limit_approaching_uses_dispatchable(): void
    {
        $this->assertContains(
            Dispatchable::class,
            class_uses_recursive(UsageLimitApproaching::class),
        );
    }

    public function test_feature_access_denied_has_properties(): void
    {
        $billable = $this->createMock(\Illuminate\Database\Eloquent\Model::class);

        $event = new FeatureAccessDenied($billable, 'analytics', 'Not in plan');

        $this->assertSame($billable, $event->billable);
        $this->assertEquals('analytics', $event->featureSlug);
        $this->assertEquals('Not in plan', $event->reason);
    }

    public function test_feature_access_denied_nullable_billable(): void
    {
        $event = new FeatureAccessDenied(null, 'analytics', 'No user');

        $this->assertNull($event->billable);
    }

    public function test_feature_access_denied_uses_dispatchable(): void
    {
        $this->assertContains(
            Dispatchable::class,
            class_uses_recursive(FeatureAccessDenied::class),
        );
    }

    public function test_subscription_renewed_has_subscription(): void
    {
        $subscription = new Subscription;
        $event = new SubscriptionRenewed($subscription);

        $this->assertSame($subscription, $event->subscription);
    }

    public function test_subscription_renewed_uses_dispatchable(): void
    {
        $this->assertContains(
            Dispatchable::class,
            class_uses_recursive(SubscriptionRenewed::class),
        );
    }
}
