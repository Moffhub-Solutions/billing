<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Services\BillingService;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class BillingServiceTest extends BaseTestCase
{
    protected BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = $this->app()->make(BillingService::class);
    }

    public function test_get_all_active_plans(): void
    {
        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Active Plan',
            'slug' => 'active-plan',
            'base_price' => 100000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Inactive Plan',
            'slug' => 'inactive-plan',
            'base_price' => 200000,
            'billing_cycle' => 'monthly',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $plans = $this->billing->plans();

        $this->assertCount(1, $plans);
        $first = $plans->first();
        $this->assertNotNull($first);
        $this->assertEquals('Active Plan', $first->name);
    }

    public function test_get_plan_by_slug(): void
    {
        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $plan = $this->billing->plan('starter');

        $this->assertNotNull($plan);
        $this->assertEquals('Starter', $plan->name);
    }

    public function test_get_plan_not_found(): void
    {
        $plan = $this->billing->plan('nonexistent');

        $this->assertNull($plan);
    }

    public function test_get_all_active_features(): void
    {
        Feature::create(['slug' => 'feature-a', 'name' => 'Feature A', 'type' => 'boolean', 'is_active' => true]);
        Feature::create(['slug' => 'feature-b', 'name' => 'Feature B', 'type' => 'boolean', 'is_active' => false]);

        $features = $this->billing->features();

        $this->assertCount(1, $features);
        $first = $features->first();
        $this->assertNotNull($first);
        $this->assertEquals('feature-a', $first->slug);
    }

    public function test_get_all_addons(): void
    {
        Feature::create(['slug' => 'core-feature', 'name' => 'Core', 'type' => 'boolean', 'is_active' => true, 'is_addon' => false]);
        Feature::create(['slug' => 'addon-feature', 'name' => 'Addon', 'type' => 'boolean', 'is_active' => true, 'is_addon' => true, 'addon_price' => 2000]);

        $addons = $this->billing->addons();

        $this->assertCount(1, $addons);
        $first = $addons->first();
        $this->assertNotNull($first);
        $this->assertEquals('addon-feature', $first->slug);
    }

    public function test_has_feature_true(): void
    {
        $company = Company::create(['name' => 'Test Co']);
        $plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Pro',
            'slug' => 'pro',
            'base_price' => 100000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook', 'incidents'],
        ]);

        $company->subscribe('pro')->create();

        $this->assertTrue($this->billing->hasFeature($company, 'gatebook'));
    }

    public function test_has_feature_false(): void
    {
        $company = Company::create(['name' => 'Test Co']);
        $plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 100000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook'],
        ]);

        $company->subscribe('starter')->create();

        $this->assertFalse($this->billing->hasFeature($company, 'analytics'));
    }

    public function test_available_features_for_billable(): void
    {
        $company = Company::create(['name' => 'Test Co']);
        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Pro',
            'slug' => 'pro',
            'base_price' => 100000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook', 'incidents', 'shifts'],
        ]);

        $company->subscribe('pro')->create();

        $features = $this->billing->availableFeatures($company);

        $this->assertContains('gatebook', $features);
        $this->assertContains('incidents', $features);
        $this->assertContains('shifts', $features);
        $this->assertCount(3, $features);
    }

    public function test_record_usage(): void
    {
        $company = Company::create(['name' => 'Test Co']);
        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Pro',
            'slug' => 'pro',
            'base_price' => 100000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['ocr_scanning'],
            'limits' => ['ocr_scanning' => 100],
        ]);

        $company->subscribe('pro')->create();

        $this->billing->recordUsage($company, 'ocr_scanning', 5);

        $usage = $this->billing->usage($company, 'ocr_scanning');
        $this->assertEquals(5, $usage);
    }

    public function test_get_usage(): void
    {
        $company = Company::create(['name' => 'Test Co']);
        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Pro',
            'slug' => 'pro',
            'base_price' => 100000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['ocr_scanning'],
            'limits' => ['ocr_scanning' => 100],
        ]);

        $company->subscribe('pro')->create();

        // No usage yet
        $this->assertEquals(0, $this->billing->usage($company, 'ocr_scanning'));

        // Record some usage
        $this->billing->recordUsage($company, 'ocr_scanning', 3);
        $this->billing->recordUsage($company, 'ocr_scanning', 7);

        $this->assertEquals(10, $this->billing->usage($company, 'ocr_scanning'));
    }

    public function test_clear_cache(): void
    {
        $company = Company::create(['name' => 'Test Co']);
        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Pro',
            'slug' => 'pro',
            'base_price' => 100000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook'],
        ]);

        $company->subscribe('pro')->create();

        // Should not throw
        $this->billing->clearCache($company);
        // No exception means success — phpunit will mark this as risky if no assertion runs
        $this->expectNotToPerformAssertions();
    }
}
