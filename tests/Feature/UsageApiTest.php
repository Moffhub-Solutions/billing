<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class UsageApiTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected Plan $proPlan;

    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Feature::create(['slug' => 'gatebook', 'name' => 'Gatebook', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        Feature::create(['slug' => 'shifts', 'name' => 'Shifts', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        Feature::create(['slug' => 'ocr_scanning', 'name' => 'OCR', 'type' => FeatureType::METERED, 'is_active' => true, 'is_addon' => true, 'addon_price' => 2000]);

        $this->proPlan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Professional',
            'slug' => 'professional',
            'base_price' => 1500000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook', 'shifts', 'ocr_scanning'],
            'limits' => ['ocr_scanning' => 500],
        ]);

        $this->company = Company::create(['name' => 'Usage Test Co']);
        $this->user = User::create([
            'name' => 'Usage User',
            'email' => 'usage@example.com',
            'company_id' => $this->company->id,
        ]);

        $this->subscription = $this->company->subscriptions()->create([
            'ulid' => Str::ulid()->toBase32(),
            'plan_id' => $this->proPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);
    }

    // ─── Usage Summary (index) ──────────────────────────────────────────

    public function test_get_usage_summary_with_records(): void
    {
        $this->company->usageRecords()->create([
            'ulid' => Str::ulid()->toBase32(),
            'feature_slug' => 'ocr_scanning',
            'period_start' => now()->subDay(),
            'period_end' => now()->addDays(29),
            'usage_count' => 50,
            'usage_limit' => 500,
            'overage_count' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/usage');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.feature_slug', 'ocr_scanning')
            ->assertJsonPath('data.0.usage_count', 50)
            ->assertJsonPath('data.0.usage_limit', 500);
    }

    public function test_get_usage_summary_empty(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/usage');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_get_usage_summary_no_billable(): void
    {
        $userNoBillable = User::create([
            'name' => 'No Company',
            'email' => 'nousage@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($userNoBillable)
            ->getJson('/api/billing/usage');

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }

    // ─── Show Usage for Feature ─────────────────────────────────────────

    public function test_show_usage_for_feature_with_record(): void
    {
        $this->company->usageRecords()->create([
            'ulid' => Str::ulid()->toBase32(),
            'feature_slug' => 'ocr_scanning',
            'period_start' => now()->subDay(),
            'period_end' => now()->addDays(29),
            'usage_count' => 100,
            'usage_limit' => 500,
            'overage_count' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/usage/ocr_scanning');

        $response->assertOk()
            ->assertJsonPath('data.feature_slug', 'ocr_scanning')
            ->assertJsonPath('data.usage_count', 100)
            ->assertJsonPath('data.usage_limit', 500)
            ->assertJsonPath('data.is_within_limit', true);
    }

    public function test_show_usage_for_feature_no_record(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/billing/usage/ocr_scanning');

        $response->assertOk()
            ->assertJsonPath('data.feature_slug', 'ocr_scanning')
            ->assertJsonPath('data.usage_count', 0)
            ->assertJsonPath('data.percentage', 0);
    }

    // ─── Record Usage ───────────────────────────────────────────────────

    public function test_record_usage(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/usage/ocr_scanning/record', [
                'quantity' => 5,
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Usage recorded.')
            ->assertJsonPath('data.feature_slug', 'ocr_scanning')
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.current_usage', 5)
            ->assertJsonPath('data.limit', 500);

        $this->assertNotNull($response->json('data.event_id'));
    }

    public function test_record_usage_default_quantity(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/usage/ocr_scanning/record');

        $response->assertCreated()
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.current_usage', 1);
    }

    public function test_record_usage_custom_quantity(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/usage/ocr_scanning/record', [
                'quantity' => 10,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.current_usage', 10);
    }

    public function test_record_usage_with_transaction_id_dedup(): void
    {
        // First call
        $response1 = $this->actingAs($this->user)
            ->postJson('/api/billing/usage/ocr_scanning/record', [
                'quantity' => 3,
                'transaction_id' => 'unique-txn-123',
            ]);

        $response1->assertCreated()
            ->assertJsonPath('data.current_usage', 3);

        // Second call with same transaction_id should be deduplicated
        $response2 = $this->actingAs($this->user)
            ->postJson('/api/billing/usage/ocr_scanning/record', [
                'quantity' => 3,
                'transaction_id' => 'unique-txn-123',
            ]);

        $response2->assertCreated();

        // Usage should still be 3, not 6
        $this->assertEquals(3, $this->company->usage('ocr_scanning'));
    }

    public function test_record_usage_with_properties(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/usage/ocr_scanning/record', [
                'quantity' => 1,
                'properties' => ['document_type' => 'invoice', 'pages' => 3],
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Usage recorded.');
    }

    public function test_record_usage_feature_not_available(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/usage/nonexistent_feature/record', [
                'quantity' => 1,
            ]);

        $response->assertForbidden()
            ->assertJsonPath('message', "Feature 'nonexistent_feature' is not available on your current plan.");
    }

    public function test_record_usage_limit_reached(): void
    {
        // Fill up usage to the limit
        $this->company->usageRecords()->create([
            'ulid' => Str::ulid()->toBase32(),
            'feature_slug' => 'ocr_scanning',
            'period_start' => now()->subDay(),
            'period_end' => now()->addDays(29),
            'usage_count' => 500,
            'usage_limit' => 500,
            'overage_count' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/usage/ocr_scanning/record', [
                'quantity' => 1,
            ]);

        $response->assertStatus(429)
            ->assertJsonPath('message', "Usage limit reached for 'ocr_scanning'.")
            ->assertJsonPath('usage.used', 500)
            ->assertJsonPath('usage.limit', 500);
    }

    public function test_record_usage_overage_allowed(): void
    {
        config(['billing.usage.allow_overage' => true]);

        // Fill up usage to the limit
        $this->company->usageRecords()->create([
            'ulid' => Str::ulid()->toBase32(),
            'feature_slug' => 'ocr_scanning',
            'period_start' => now()->subDay(),
            'period_end' => now()->addDays(29),
            'usage_count' => 500,
            'usage_limit' => 500,
            'overage_count' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/usage/ocr_scanning/record', [
                'quantity' => 1,
            ]);

        // Should succeed when overage is allowed
        $response->assertCreated()
            ->assertJsonPath('message', 'Usage recorded.');
    }

    public function test_record_usage_no_billable(): void
    {
        $userNoBillable = User::create([
            'name' => 'No Company',
            'email' => 'nousage2@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($userNoBillable)
            ->postJson('/api/billing/usage/ocr_scanning/record', [
                'quantity' => 1,
            ]);

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }
}
