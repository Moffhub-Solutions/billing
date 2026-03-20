<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Tests\BaseTestCase;

class PlanTest extends BaseTestCase
{
    public function test_can_create_plan(): void
    {
        $plan = Plan::create([
            'ulid' => '01JTEST000000000000000001',
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'Basic plan',
            'base_price' => 250000,
            'currency' => 'KES',
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook', 'incidents'],
            'limits' => ['max_posts' => 2, 'max_guards' => 5],
        ]);

        $this->assertDatabaseHas(config('billing.tables.plans'), [
            'slug' => 'starter',
            'base_price' => 250000,
        ]);

        $this->assertEquals(BillingCycle::MONTHLY, $plan->billing_cycle);
        $this->assertTrue($plan->is_active);
    }

    public function test_plan_has_feature(): void
    {
        $plan = Plan::create([
            'ulid' => '01JTEST000000000000000002',
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook', 'incidents', 'shifts'],
            'limits' => ['max_posts' => 10],
        ]);

        $this->assertTrue($plan->hasFeature('gatebook'));
        $this->assertTrue($plan->hasFeature('shifts'));
        $this->assertFalse($plan->hasFeature('hr_management'));
    }

    public function test_plan_get_limit(): void
    {
        $plan = Plan::create([
            'ulid' => '01JTEST000000000000000003',
            'name' => 'Starter',
            'slug' => 'starter-limits',
            'base_price' => 250000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook'],
            'limits' => ['max_posts' => 2, 'max_guards' => 5, 'max_entries_per_month' => 500],
        ]);

        $this->assertEquals(2, $plan->getLimit('max_posts'));
        $this->assertEquals(5, $plan->getLimit('max_guards'));
        $this->assertEquals(500, $plan->getLimit('max_entries_per_month'));
        $this->assertNull($plan->getLimit('nonexistent'));
    }

    public function test_plan_monthly_price_calculation(): void
    {
        $monthly = Plan::create([
            'ulid' => '01JTEST000000000000000004',
            'name' => 'Monthly',
            'slug' => 'monthly-test',
            'base_price' => 100000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
        ]);

        $annual = Plan::create([
            'ulid' => '01JTEST000000000000000005',
            'name' => 'Annual',
            'slug' => 'annual-test',
            'base_price' => 1000000,
            'billing_cycle' => BillingCycle::ANNUAL,
            'is_active' => true,
        ]);

        $this->assertEquals(100000, $monthly->monthlyPrice());
        $this->assertEquals(83333, $annual->monthlyPrice());
    }

    public function test_active_scope(): void
    {
        Plan::create(['ulid' => '01JTEST000000000000000006', 'name' => 'Active', 'slug' => 'active', 'base_price' => 100, 'billing_cycle' => 'monthly', 'is_active' => true]);
        Plan::create(['ulid' => '01JTEST000000000000000007', 'name' => 'Inactive', 'slug' => 'inactive', 'base_price' => 100, 'billing_cycle' => 'monthly', 'is_active' => false]);

        $this->assertEquals(1, Plan::active()->count());
    }
}
