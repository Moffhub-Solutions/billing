<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class BillingApiTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Feature::create(['slug' => 'gatebook', 'name' => 'Gatebook', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        Feature::create(['slug' => 'shifts', 'name' => 'Shifts', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        Feature::create(['slug' => 'ocr_scanning', 'name' => 'OCR', 'type' => FeatureType::METERED, 'is_active' => true, 'is_addon' => true, 'addon_price' => 2000]);

        Plan::create([
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

        Plan::create([
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

        $this->company = Company::create(['name' => 'API Test Co']);
        $this->user = User::create([
            'name' => 'API User',
            'email' => 'api@example.com',
            'company_id' => $this->company->id,
        ]);
    }

    // ─── Plans API ─────────────────────────────────────────────────────

    public function test_list_plans(): void
    {
        $response = $this->getJson('/api/billing/plans');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'starter')
            ->assertJsonPath('data.1.slug', 'professional');
    }

    public function test_show_plan_by_slug(): void
    {
        $response = $this->getJson('/api/billing/plans/starter');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'starter')
            ->assertJsonPath('data.name', 'Starter')
            ->assertJsonPath('data.base_price', 250000)
            ->assertJsonPath('data.billing_cycle', 'monthly');
    }

    public function test_show_plan_not_found(): void
    {
        $response = $this->getJson('/api/billing/plans/nonexistent');

        $response->assertNotFound();
    }

    // ─── Features API ──────────────────────────────────────────────────

    public function test_list_features(): void
    {
        $response = $this->getJson('/api/billing/features');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_list_features_filtered_by_category(): void
    {
        Feature::where('slug', 'gatebook')->update(['category' => 'core']);
        Feature::where('slug', 'shifts')->update(['category' => 'operations']);
        Feature::where('slug', 'ocr_scanning')->update(['category' => 'addons']);

        $response = $this->getJson('/api/billing/features?category=core');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'gatebook');
    }

    public function test_list_addons(): void
    {
        $response = $this->getJson('/api/billing/features/addons');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'ocr_scanning');
    }

    public function test_show_feature(): void
    {
        $response = $this->getJson('/api/billing/features/ocr_scanning');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'ocr_scanning')
            ->assertJsonPath('data.is_addon', true)
            ->assertJsonPath('data.is_trackable', true);
    }

    // ─── Admin Plans API ───────────────────────────────────────────────

    public function test_create_plan(): void
    {
        $response = $this->postJson('/api/billing/admin/plans', [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'description' => 'For large companies',
            'base_price' => 3500000,
            'billing_cycle' => 'monthly',
            'features' => ['gatebook', 'shifts', 'ocr_scanning'],
            'limits' => [],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'enterprise')
            ->assertJsonPath('data.base_price', 3500000);

        $this->assertDatabaseHas('billing_plans', ['slug' => 'enterprise']);
    }

    public function test_create_plan_validates_required_fields(): void
    {
        $response = $this->postJson('/api/billing/admin/plans', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'slug', 'base_price', 'billing_cycle']);
    }

    public function test_create_plan_validates_unique_slug(): void
    {
        $response = $this->postJson('/api/billing/admin/plans', [
            'name' => 'Duplicate',
            'slug' => 'starter', // already exists
            'base_price' => 100,
            'billing_cycle' => 'monthly',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    // ─── Admin Features API ────────────────────────────────────────────

    public function test_create_feature(): void
    {
        $response = $this->postJson('/api/billing/admin/features', [
            'slug' => 'patrol_checkpoints',
            'name' => 'Guard Patrol',
            'type' => 'metered',
            'is_addon' => true,
            'addon_price' => 3000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'patrol_checkpoints');

        $this->assertDatabaseHas('billing_features', ['slug' => 'patrol_checkpoints']);
    }

    public function test_create_feature_validates_unique_slug(): void
    {
        $response = $this->postJson('/api/billing/admin/features', [
            'slug' => 'gatebook', // already exists
            'name' => 'Duplicate',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }
}
