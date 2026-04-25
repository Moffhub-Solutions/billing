<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\SubscriptionCreated;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Services\SubscriptionBuilder;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class SubscriptionBuilderTest extends BaseTestCase
{
    protected Plan $plan;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
            'trial_days' => 14,
            'features' => ['gatebook', 'incidents'],
        ]);

        $this->company = Company::create(['name' => 'Test Company']);
    }

    public function test_create_basic_subscription(): void
    {
        Event::fake();

        $subscription = $this->company->subscribe('starter')->create();

        $this->assertEquals($this->plan->id, $subscription->plan_id);
        $this->assertTrue($subscription->status === SubscriptionStatus::TRIALING);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertNotNull($subscription->current_period_start);
        $this->assertNotNull($subscription->current_period_end);
    }

    public function test_create_with_trial(): void
    {
        Event::fake();

        $subscription = $this->company->subscribe('starter')
            ->trialDays(7)
            ->create();

        $this->assertEquals(SubscriptionStatus::TRIALING, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
        // Trial should end roughly 7 days from now
        $this->assertTrue($subscription->trial_ends_at->diffInDays(now()) <= 7);
    }

    public function test_create_with_provider(): void
    {
        Event::fake();

        $subscription = $this->company->subscribe('starter')
            ->trialDays(0)
            ->provider('mpesa', 'sub_123')
            ->create();

        $this->assertEquals('mpesa', $subscription->payment_provider);
        $this->assertEquals('sub_123', $subscription->provider_subscription_id);
    }

    public function test_create_with_metadata(): void
    {
        Event::fake();

        $metadata = ['source' => 'web', 'campaign' => 'launch'];

        $subscription = $this->company->subscribe('starter')
            ->withMetadata($metadata)
            ->create();

        $this->assertEquals($metadata, $subscription->metadata);
    }

    public function test_create_dispatches_event(): void
    {
        Event::fake();

        $subscription = $this->company->subscribe('starter')->create();

        Event::assertDispatched(SubscriptionCreated::class, function ($event) use ($subscription) {
            return $event->subscription->id === $subscription->id;
        });
    }

    public function test_create_invalid_plan(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->company->subscribe('nonexistent-plan')->create();
    }

    public function test_fluent_chaining(): void
    {
        $builder = $this->company->subscribe('starter');

        $this->assertInstanceOf(SubscriptionBuilder::class, $builder);

        $result = $builder->trialDays(7);
        $this->assertInstanceOf(SubscriptionBuilder::class, $result);

        $result = $result->provider('manual');
        $this->assertInstanceOf(SubscriptionBuilder::class, $result);

        $result = $result->withMetadata(['key' => 'value']);
        $this->assertInstanceOf(SubscriptionBuilder::class, $result);
    }

    public function test_trial_days_from_plan_default(): void
    {
        Event::fake();

        // Plan has trial_days = 14, no explicit trial set
        $subscription = $this->company->subscribe('starter')->create();

        $this->assertEquals(SubscriptionStatus::TRIALING, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
    }
}
