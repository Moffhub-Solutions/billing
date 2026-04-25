<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class SubscriptionTest extends BaseTestCase
{
    protected Plan $plan;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'ulid' => '01JTEST000000000000000010',
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook', 'incidents', 'shifts'],
            'limits' => ['max_posts' => 10, 'ocr_scanning' => 100],
        ]);

        $this->company = Company::create(['name' => 'Test Co']);
    }

    public function test_can_create_subscription(): void
    {
        $subscription = $this->company->subscribe('standard')->create();

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertEquals(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->isActive());
        $this->assertFalse($subscription->onTrial());
    }

    public function test_subscription_with_trial(): void
    {
        $subscription = $this->company->subscribe('standard')
            ->trialDays(14)
            ->create();

        $this->assertEquals(SubscriptionStatus::TRIALING, $subscription->status);
        $this->assertTrue($subscription->onTrial());
        $this->assertTrue($subscription->isActive());
        $this->assertNotNull($subscription->trial_ends_at);
    }

    public function test_subscription_has_feature(): void
    {
        $this->company->subscribe('standard')->create();

        $this->assertTrue($this->company->hasFeature('gatebook'));
        $this->assertTrue($this->company->hasFeature('shifts'));
        $this->assertFalse($this->company->hasFeature('hr_management'));
    }

    public function test_billable_subscribed(): void
    {
        $this->assertFalse($this->company->subscribed());

        $this->company->subscribe('standard')->create();

        $this->assertTrue($this->company->subscribed());
    }

    public function test_billable_on_plan(): void
    {
        $this->company->subscribe('standard')->create();

        $this->assertTrue($this->company->onPlan('standard'));
        $this->assertFalse($this->company->onPlan('professional'));
    }

    public function test_cancel_subscription(): void
    {
        $subscription = $this->company->subscribe('standard')->create();

        $subscription->cancel();

        $this->assertNotNull($subscription->cancelled_at);
        // Still active until period ends (grace period)
        $this->assertTrue($subscription->onGracePeriod());
    }

    public function test_cancel_subscription_immediately(): void
    {
        $subscription = $this->company->subscribe('standard')->create();

        $subscription->cancel(immediately: true);

        $this->assertEquals(SubscriptionStatus::CANCELLED, $subscription->status);
        $this->assertTrue($subscription->cancelled());
    }

    public function test_pause_and_resume_subscription(): void
    {
        $subscription = $this->company->subscribe('standard')->create();

        $subscription->pause();
        $pausedFresh = $subscription->fresh();
        $this->assertNotNull($pausedFresh);
        $this->assertEquals(SubscriptionStatus::PAUSED, $pausedFresh->status);

        $subscription->resume();
        $resumedFresh = $subscription->fresh();
        $this->assertNotNull($resumedFresh);
        $this->assertEquals(SubscriptionStatus::ACTIVE, $resumedFresh->status);
    }

    public function test_usage_limit(): void
    {
        $this->company->subscribe('standard')->create();

        $this->assertEquals(10, $this->company->usageLimit('max_posts'));
        $this->assertEquals(100, $this->company->usageLimit('ocr_scanning'));
        $this->assertNull($this->company->usageLimit('nonexistent'));
    }

    public function test_is_active_returns_false_when_period_has_expired(): void
    {
        // Regression: a subscription whose `current_period_end` has passed but
        // whose status is still `ACTIVE` (because the renewal job hasn't run
        // yet) used to incorrectly report `isActive() === true`. The customer's
        // paid window is over and they should not have feature access.
        $subscription = $this->company->subscribe('standard')->create();

        $subscription->update([
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
        ]);

        $subscription->refresh();

        $this->assertEquals(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->expired());
        $this->assertFalse($subscription->isActive());
    }

    public function test_is_active_returns_true_when_period_in_future(): void
    {
        $subscription = $this->company->subscribe('standard')->create();

        $this->assertTrue($subscription->isActive());
        $this->assertFalse($subscription->expired());
    }
}
