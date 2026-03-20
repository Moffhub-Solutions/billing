<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Events\PlanChanged;
use Moffhub\Billing\Events\SubscriptionCancelled;
use Moffhub\Billing\Events\SubscriptionCreated;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class SubscriptionApiTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected Plan $starterPlan;

    protected Plan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        Feature::create(['slug' => 'gatebook', 'name' => 'Gatebook', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        Feature::create(['slug' => 'shifts', 'name' => 'Shifts', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        Feature::create(['slug' => 'ocr_scanning', 'name' => 'OCR', 'type' => FeatureType::METERED, 'is_active' => true, 'is_addon' => true, 'addon_price' => 2000]);

        $this->starterPlan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook'],
            'limits' => ['max_posts' => 2],
        ]);

        $this->proPlan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Professional',
            'slug' => 'professional',
            'base_price' => 1500000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 2,
            'features' => ['gatebook', 'shifts', 'ocr_scanning'],
            'limits' => ['ocr_scanning' => 500],
        ]);

        $this->company = Company::create(['name' => 'Test Co']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'company_id' => $this->company->id,
        ]);
    }

    // ─── List Subscriptions ─────────────────────────────────────────────

    public function test_list_subscriptions(): void
    {
        $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/subscriptions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'status', 'is_active', 'plan']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_list_subscriptions_with_pagination(): void
    {
        // Create multiple subscriptions
        for ($i = 0; $i < 3; $i++) {
            $this->createSubscription($this->company, $this->starterPlan, SubscriptionStatus::CANCELLED);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/subscriptions?per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_list_subscriptions_with_status_filter(): void
    {
        $this->createSubscription($this->company, $this->starterPlan, SubscriptionStatus::ACTIVE);
        $this->createSubscription($this->company, $this->starterPlan, SubscriptionStatus::CANCELLED);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/subscriptions?status=active');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'active');
    }

    public function test_list_subscriptions_empty(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/subscriptions');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_list_subscriptions_no_billable(): void
    {
        $userNoBillable = User::create([
            'name' => 'No Company',
            'email' => 'nocompany@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($userNoBillable)
            ->getJson('/api/billing/subscriptions');

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }

    // ─── Current Subscription ───────────────────────────────────────────

    public function test_get_current_subscription(): void
    {
        $this->createSubscription($this->company, $this->proPlan);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/subscriptions/current');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'status', 'is_active', 'plan'],
                'features',
                'usage',
            ])
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_get_current_subscription_includes_features_and_usage(): void
    {
        $this->createSubscription($this->company, $this->proPlan);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/subscriptions/current');

        $response->assertOk();

        $data = $response->json();

        // Should include features from the plan
        $this->assertContains('gatebook', $data['features']);
        $this->assertContains('shifts', $data['features']);
        $this->assertContains('ocr_scanning', $data['features']);

        // Should include usage summary for limits
        $this->assertArrayHasKey('ocr_scanning', $data['usage']);
        $this->assertEquals(0, $data['usage']['ocr_scanning']['used']);
        $this->assertEquals(500, $data['usage']['ocr_scanning']['limit']);
    }

    public function test_get_current_subscription_no_subscription(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/subscriptions/current');

        $response->assertNotFound()
            ->assertJsonPath('message', 'No active subscription.');
    }

    public function test_get_current_subscription_no_billable(): void
    {
        $userNoBillable = User::create([
            'name' => 'No Company',
            'email' => 'nocompany2@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($userNoBillable)
            ->getJson('/api/billing/subscriptions/current');

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }

    // ─── Show Subscription ──────────────────────────────────────────────

    public function test_show_subscription(): void
    {
        $subscription = $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->getJson("/api/billing/subscriptions/{$subscription->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonStructure([
                'data' => ['id', 'status', 'is_active', 'plan'],
            ]);
    }

    public function test_show_subscription_not_found(): void
    {
        $response = $this->getJson('/api/billing/subscriptions/99999');

        $response->assertNotFound();
    }

    // ─── Create Subscription ────────────────────────────────────────────

    public function test_create_subscription(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/subscriptions', [
                'plan' => 'starter',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Subscription created successfully.')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('billing_subscriptions', [
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'plan_id' => $this->starterPlan->id,
            'status' => 'active',
        ]);

        Event::assertDispatched(SubscriptionCreated::class);
    }

    public function test_create_subscription_with_trial(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/subscriptions', [
                'plan' => 'starter',
                'trial_days' => 14,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.on_trial', true);
    }

    public function test_create_subscription_with_provider(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/subscriptions', [
                'plan' => 'starter',
                'payment_provider' => 'manual',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_provider', 'manual');
    }

    public function test_create_subscription_with_metadata(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/subscriptions', [
                'plan' => 'starter',
                'metadata' => ['source' => 'api', 'campaign' => 'onboarding'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.metadata.source', 'api')
            ->assertJsonPath('data.metadata.campaign', 'onboarding');
    }

    public function test_create_subscription_already_subscribed(): void
    {
        $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/subscriptions', [
                'plan' => 'professional',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Already subscribed. Use change-plan to switch plans.');
    }

    public function test_create_subscription_no_billable(): void
    {
        $userNoBillable = User::create([
            'name' => 'No Company',
            'email' => 'nocompany3@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($userNoBillable)
            ->postJson('/api/billing/subscriptions', [
                'plan' => 'starter',
            ]);

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }

    public function test_create_subscription_invalid_plan(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/subscriptions', [
                'plan' => 'nonexistent-plan',
            ]);

        $response->assertNotFound();
    }

    public function test_create_subscription_validation_requires_plan(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/subscriptions', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['plan']);
    }

    // ─── Change Plan ────────────────────────────────────────────────────

    public function test_change_plan(): void
    {
        Event::fake([PlanChanged::class]);

        $subscription = $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->putJson("/api/billing/subscriptions/{$subscription->id}/change-plan", [
            'plan' => 'professional',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Plan changed from Starter to Professional.');

        $this->assertDatabaseHas('billing_subscriptions', [
            'id' => $subscription->id,
            'plan_id' => $this->proPlan->id,
        ]);

        Event::assertDispatched(PlanChanged::class, function (PlanChanged $event) {
            return $event->oldPlan->slug === 'starter'
                && $event->newPlan->slug === 'professional';
        });
    }

    public function test_change_plan_same_plan_error(): void
    {
        $subscription = $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->putJson("/api/billing/subscriptions/{$subscription->id}/change-plan", [
            'plan' => 'starter',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Already on this plan.');
    }

    public function test_change_plan_validation_requires_plan(): void
    {
        $subscription = $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->putJson("/api/billing/subscriptions/{$subscription->id}/change-plan", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['plan']);
    }

    // ─── Cancel Subscription ────────────────────────────────────────────

    public function test_cancel_subscription_immediately(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        $subscription = $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->postJson("/api/billing/subscriptions/{$subscription->id}/cancel", [
            'immediately' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Subscription cancelled immediately.')
            ->assertJsonPath('data.status', 'cancelled');

        Event::assertDispatched(SubscriptionCancelled::class, function (SubscriptionCancelled $event) {
            return $event->immediately === true;
        });
    }

    public function test_cancel_subscription_grace_period(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        $subscription = $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->postJson("/api/billing/subscriptions/{$subscription->id}/cancel", [
            'immediately' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Subscription will be cancelled at the end of the billing period.')
            ->assertJsonPath('data.status', 'active'); // Status stays active during grace period

        // cancelled_at should be set
        $subscription->refresh();
        $this->assertNotNull($subscription->cancelled_at);

        Event::assertDispatched(SubscriptionCancelled::class, function (SubscriptionCancelled $event) {
            return $event->immediately === false;
        });
    }

    // ─── Pause Subscription ─────────────────────────────────────────────

    public function test_pause_subscription(): void
    {
        $subscription = $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->postJson("/api/billing/subscriptions/{$subscription->id}/pause");

        $response->assertOk()
            ->assertJsonPath('message', 'Subscription paused.')
            ->assertJsonPath('data.status', 'paused');
    }

    public function test_pause_subscription_disabled_in_config(): void
    {
        config(['billing.subscriptions.allow_pause' => false]);

        $subscription = $this->createSubscription($this->company, $this->starterPlan);

        $response = $this->postJson("/api/billing/subscriptions/{$subscription->id}/pause");

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Pausing subscriptions is not enabled.');
    }

    // ─── Resume Subscription ────────────────────────────────────────────

    public function test_resume_subscription(): void
    {
        $subscription = $this->createSubscription($this->company, $this->starterPlan, SubscriptionStatus::PAUSED);

        $response = $this->postJson("/api/billing/subscriptions/{$subscription->id}/resume");

        $response->assertOk()
            ->assertJsonPath('message', 'Subscription resumed.')
            ->assertJsonPath('data.status', 'active');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    protected function createSubscription(
        Company $company,
        Plan $plan,
        SubscriptionStatus $status = SubscriptionStatus::ACTIVE,
    ): Subscription {
        return $company->subscriptions()->create([
            'ulid' => Str::ulid()->toBase32(),
            'plan_id' => $plan->id,
            'status' => $status,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);
    }
}
