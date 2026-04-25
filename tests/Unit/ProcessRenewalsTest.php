<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\ExpectationInterface;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\SubscriptionCancelled;
use Moffhub\Billing\Events\SubscriptionRenewed;
use Moffhub\Billing\Jobs\ProcessDunning;
use Moffhub\Billing\Jobs\ProcessRenewals;
use Moffhub\Billing\Jobs\ProcessTrialConversions;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Providers\ManualProvider;
use Moffhub\Billing\Services\ProrationCalculator;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class ProcessRenewalsTest extends BaseTestCase
{
    protected Plan $plan;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'ulid' => '01JTEST000000000000000020',
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 500000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook', 'incidents'],
            'limits' => ['max_posts' => 10],
        ]);

        $this->company = Company::create(['name' => 'Renewal Co']);
    }

    public function test_renewal_success_extends_period_creates_payment_dispatches_event(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $subscription = $this->createExpiredSubscription();

        $this->mockSuccessfulPayment();

        $job = new ProcessRenewals;
        app()->call([$job, 'handle']);

        $subscription->refresh();

        $this->assertEquals(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertNotNull($subscription->current_period_start);
        $this->assertTrue($subscription->current_period_end->isFuture());
        $this->assertTrue($subscription->current_period_start->isToday());

        // Payment record created
        $payment = Payment::where('subscription_id', $subscription->id)
            ->where('status', PaymentStatus::COMPLETED)
            ->first();

        $this->assertNotNull($payment);
        $this->assertEquals(500000, $payment->amount);

        Event::assertDispatched(SubscriptionRenewed::class, function ($event) use ($subscription) {
            return $event->subscription->id === $subscription->id;
        });
    }

    public function test_renewal_failure_marks_past_due_and_dispatches_payment_failed(): void
    {
        Event::fake([PaymentFailed::class]);

        $subscription = $this->createExpiredSubscription();

        $this->mockFailedPayment();

        $job = new ProcessRenewals;
        app()->call([$job, 'handle']);

        $subscription->refresh();

        $this->assertEquals(SubscriptionStatus::PAST_DUE, $subscription->status);

        $payment = Payment::where('subscription_id', $subscription->id)
            ->where('status', PaymentStatus::FAILED)
            ->first();

        $this->assertNotNull($payment);

        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_skips_non_expired_subscriptions(): void
    {
        Event::fake([SubscriptionRenewed::class, PaymentFailed::class]);

        // Create a subscription that has NOT expired (period end is in the future)
        $subscription = $this->company->subscribe('standard')->create();

        $job = new ProcessRenewals;
        app()->call([$job, 'handle']);

        $subscription->refresh();

        $this->assertEquals(SubscriptionStatus::ACTIVE, $subscription->status);

        Event::assertNotDispatched(SubscriptionRenewed::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_skips_subscriptions_cancelled_during_grace_period(): void
    {
        // Regression: `cancel(immediately: false)` keeps `status = ACTIVE`
        // until the period ends. Without filtering on `cancelled_at`, the
        // renewal job would charge a customer who explicitly cancelled.
        Event::fake([SubscriptionRenewed::class, PaymentFailed::class]);

        $subscription = $this->createExpiredSubscription();
        $subscription->cancel(immediately: false);

        $reloaded = $subscription->fresh();
        $this->assertNotNull($reloaded);
        $this->assertEquals(SubscriptionStatus::ACTIVE, $reloaded->status);
        $this->assertNotNull($reloaded->cancelled_at);

        $this->mockSuccessfulPayment();

        $job = new ProcessRenewals;
        app()->call([$job, 'handle']);

        // No payment row should have been created
        $this->assertSame(0, Payment::where('subscription_id', $subscription->id)->count());

        Event::assertNotDispatched(SubscriptionRenewed::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_renewal_uses_metadata_amount_for_variable_priced_subscriptions(): void
    {
        // Variable-priced subscriptions (per-stream, per-seat, per-MRR-tier)
        // persist their billable amount in `metadata.amount` so the renewal
        // job charges the right value rather than the plan's flat base price.
        Event::fake([SubscriptionRenewed::class]);

        $subscription = $this->createExpiredSubscription();
        $subscription->update([
            'metadata' => ['amount' => 1234500],
        ]);

        $this->mockSuccessfulPayment();

        $job = new ProcessRenewals;
        app()->call([$job, 'handle']);

        $payment = Payment::where('subscription_id', $subscription->id)
            ->where('status', PaymentStatus::COMPLETED)
            ->first();

        $this->assertNotNull($payment);
        $this->assertSame(1234500, $payment->amount);
        $this->assertNotSame($this->plan->base_price, $payment->amount);

        Event::assertDispatched(SubscriptionRenewed::class, fn ($event) => $event->paymentAmount === 1234500);
    }

    public function test_trial_conversion_success(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $subscription = $this->createExpiredTrialSubscription();

        $this->mockSuccessfulPayment();

        $job = new ProcessTrialConversions;
        app()->call([$job, 'handle']);

        $subscription->refresh();

        $this->assertEquals(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertTrue($subscription->current_period_end->isFuture());

        $payment = Payment::where('subscription_id', $subscription->id)
            ->where('status', PaymentStatus::COMPLETED)
            ->first();

        $this->assertNotNull($payment);

        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_trial_conversion_failure(): void
    {
        Event::fake([PaymentFailed::class]);

        $subscription = $this->createExpiredTrialSubscription();

        $this->mockFailedPayment();

        $job = new ProcessTrialConversions;
        app()->call([$job, 'handle']);

        $subscription->refresh();

        // Should be past_due since we're still within the grace period
        $this->assertContains($subscription->status, [
            SubscriptionStatus::PAST_DUE,
            SubscriptionStatus::EXPIRED,
        ]);

        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_dunning_retry_success(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        // Create a past_due subscription where period ended 1 day ago (matches dunning schedule [1, 3, 7])
        $subscription = $this->createPastDueSubscription(daysAgo: 1);

        $this->mockSuccessfulPayment();

        $job = new ProcessDunning;
        app()->call([$job, 'handle']);

        $subscription->refresh();

        $this->assertEquals(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertTrue($subscription->current_period_end->isFuture());

        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_dunning_exhausted_cancels_subscription(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        // Create a past_due subscription where period ended well beyond max retry + grace period
        // Default dunning schedule: [1, 3, 7], grace period: 7 days
        // So after 7 + 7 + 1 = 15 days, it should be cancelled
        $subscription = $this->createPastDueSubscription(daysAgo: 15);

        $job = new ProcessDunning;
        app()->call([$job, 'handle']);

        $subscription->refresh();

        $this->assertEquals(SubscriptionStatus::CANCELLED, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);

        Event::assertDispatched(SubscriptionCancelled::class);
    }

    public function test_proration_calculation_upgrade(): void
    {
        $calculator = new ProrationCalculator;

        $subscription = $this->company->subscribe('standard')->create();

        // Simulate being halfway through the billing period
        $subscription->update([
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->addDays(15),
        ]);

        $premiumPlan = Plan::create([
            'ulid' => '01JTEST000000000000000021',
            'name' => 'Premium',
            'slug' => 'premium',
            'base_price' => 1000000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook', 'incidents', 'hr'],
            'limits' => ['max_posts' => 100],
        ]);

        $result = $calculator->calculate($subscription->fresh() ?? $subscription, $premiumPlan);

        $this->assertArrayHasKey('credit', $result);
        $this->assertArrayHasKey('charge', $result);
        $this->assertArrayHasKey('net', $result);

        // Credit should be approximately half of old plan price
        $this->assertGreaterThan(0, $result['credit']);
        // Charge should be approximately half of new plan price
        $this->assertGreaterThan(0, $result['charge']);
        // Net should be positive for an upgrade (new plan is more expensive)
        $this->assertGreaterThan(0, $result['net']);
    }

    public function test_proration_calculation_downgrade(): void
    {
        $calculator = new ProrationCalculator;

        $premiumPlan = Plan::create([
            'ulid' => '01JTEST000000000000000022',
            'name' => 'Premium',
            'slug' => 'premium',
            'base_price' => 1000000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook', 'incidents', 'hr'],
            'limits' => ['max_posts' => 100],
        ]);

        $subscription = $this->company->subscribe('premium')->create();

        // Simulate being halfway through the billing period
        $subscription->update([
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->addDays(15),
        ]);

        $result = $calculator->calculate($subscription->fresh() ?? $subscription, $this->plan);

        // Net should be negative for a downgrade (refund owed)
        $this->assertLessThan(0, $result['net']);
    }

    public function test_proration_calculation_same_plan(): void
    {
        $calculator = new ProrationCalculator;

        $subscription = $this->company->subscribe('standard')->create();

        $subscription->update([
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->addDays(15),
        ]);

        $result = $calculator->calculate($subscription->fresh() ?? $subscription, $this->plan);

        // Same plan = net should be zero
        $this->assertEquals(0, $result['net']);
    }

    // --- Helper methods ---

    protected function createExpiredSubscription(): Subscription
    {
        $subscription = $this->company->subscribe('standard')->create();

        // Set the period to have already ended
        $subscription->update([
            'current_period_start' => now()->subDays(30),
            'current_period_end' => now()->subDay(),
        ]);

        $fresh = $subscription->fresh();
        $this->assertNotNull($fresh);

        return $fresh;
    }

    protected function createExpiredTrialSubscription(): Subscription
    {
        $subscription = $this->company->subscribe('standard')
            ->trialDays(14)
            ->create();

        // Set the trial to have already ended
        $subscription->update([
            'trial_ends_at' => now()->subDay(),
        ]);

        $fresh = $subscription->fresh();
        $this->assertNotNull($fresh);

        return $fresh;
    }

    protected function createPastDueSubscription(int $daysAgo): Subscription
    {
        $subscription = $this->company->subscribe('standard')->create();

        $subscription->update([
            'status' => SubscriptionStatus::PAST_DUE,
            'current_period_start' => now()->subDays(30 + $daysAgo),
            'current_period_end' => now()->subDays($daysAgo),
        ]);

        $fresh = $subscription->fresh();
        $this->assertNotNull($fresh);

        return $fresh;
    }

    protected function mockSuccessfulPayment(): void
    {
        $mockProvider = Mockery::mock(ManualProvider::class)->makePartial();
        $chargeExp = $mockProvider->shouldReceive('charge');
        if ($chargeExp instanceof ExpectationInterface) {
            $chargeExp->andReturn([
                'success' => true,
                'provider_payment_id' => 'test_pay_'.uniqid(),
                'provider_reference' => null,
                'status' => 'completed',
                'metadata' => [],
            ]);
        }

        $mockManager = Mockery::mock(PaymentManager::class)->makePartial();
        $driverExp = $mockManager->shouldReceive('driver');
        if ($driverExp instanceof ExpectationInterface) {
            $driverExp->andReturn($mockProvider);
        }
        $defaultExp = $mockManager->shouldReceive('getDefaultDriver');
        if ($defaultExp instanceof ExpectationInterface) {
            $defaultExp->andReturn('manual');
        }

        $this->app()->instance(PaymentManager::class, $mockManager);
    }

    protected function mockFailedPayment(): void
    {
        $mockProvider = Mockery::mock(ManualProvider::class)->makePartial();
        $chargeExp = $mockProvider->shouldReceive('charge');
        if ($chargeExp instanceof ExpectationInterface) {
            $chargeExp->andReturn([
                'success' => false,
                'provider_payment_id' => null,
                'provider_reference' => null,
                'status' => 'failed',
                'metadata' => ['error' => 'Insufficient funds'],
            ]);
        }

        $mockManager = Mockery::mock(PaymentManager::class)->makePartial();
        $driverExp = $mockManager->shouldReceive('driver');
        if ($driverExp instanceof ExpectationInterface) {
            $driverExp->andReturn($mockProvider);
        }
        $defaultExp = $mockManager->shouldReceive('getDefaultDriver');
        if ($defaultExp instanceof ExpectationInterface) {
            $defaultExp->andReturn('manual');
        }

        $this->app()->instance(PaymentManager::class, $mockManager);
    }
}
