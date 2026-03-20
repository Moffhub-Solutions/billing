<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Tests\BaseTestCase;

class CommandTest extends BaseTestCase
{
    public function test_sync_plans_displays_table(): void
    {
        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook'],
        ]);

        $this->artisan('billing:sync-plans')
            ->expectsOutputToContain('Syncing billing plans and features')
            ->expectsOutputToContain('Plans: 1')
            ->assertExitCode(0);
    }

    public function test_sync_plans_seed_creates_plans_and_features(): void
    {
        $this->artisan('billing:sync-plans', ['--seed' => true])
            ->expectsOutputToContain('Seeding default features')
            ->expectsOutputToContain('Seeding default plans')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, Plan::count());
        $this->assertGreaterThan(0, Feature::count());

        // Verify specific plans were seeded
        $this->assertNotNull(Plan::where('slug', 'starter')->first());
        $this->assertNotNull(Plan::where('slug', 'standard')->first());
        $this->assertNotNull(Plan::where('slug', 'professional')->first());
        $this->assertNotNull(Plan::where('slug', 'enterprise')->first());
    }

    public function test_sync_plans_seed_dry_run(): void
    {
        $this->artisan('billing:sync-plans', ['--seed' => true, '--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);

        $this->assertEquals(0, Plan::count());
        $this->assertEquals(0, Feature::count());
    }

    public function test_sync_plans_seed_idempotent(): void
    {
        // Run seed twice
        $this->artisan('billing:sync-plans', ['--seed' => true])->assertExitCode(0);
        $planCountFirst = Plan::count();
        $featureCountFirst = Feature::count();

        $this->artisan('billing:sync-plans', ['--seed' => true])->assertExitCode(0);
        $planCountSecond = Plan::count();
        $featureCountSecond = Feature::count();

        $this->assertEquals($planCountFirst, $planCountSecond);
        $this->assertEquals($featureCountFirst, $featureCountSecond);
    }

    public function test_billing_health_runs(): void
    {
        $this->artisan('billing:health')
            ->expectsOutputToContain('Billing Health Check')
            ->expectsOutputToContain('Plans:')
            ->expectsOutputToContain('Features:')
            ->expectsOutputToContain('Subscriptions:')
            ->assertExitCode(0);
    }

    public function test_billing_health_shows_providers(): void
    {
        $this->artisan('billing:health')
            ->expectsOutputToContain('Payment Providers')
            ->expectsOutputToContain('manual')
            ->expectsOutputToContain('Configuration')
            ->assertExitCode(0);
    }
}
