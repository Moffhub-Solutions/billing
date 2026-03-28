<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Services\FeatureResolver;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class AdminBypassTest extends BaseTestCase
{
    protected Plan $plan;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'ulid' => '01JTEST000000000000000090',
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook', 'incidents'],
            'limits' => ['max_posts' => 5],
        ]);

        $this->company = Company::create(['name' => 'Test Co']);
    }

    // ── Billable trait: hasFeatureOrAdmin ─────────────────────────

    public function test_has_feature_or_admin_returns_feature_check_when_no_bypass_configured(): void
    {
        $this->app['config']->set('billing.admin_bypass_method', null);

        $this->company->subscribe('starter')->create();

        $this->assertTrue($this->company->hasFeatureOrAdmin('gatebook'));
        $this->assertFalse($this->company->hasFeatureOrAdmin('hr_management'));
    }

    public function test_has_feature_or_admin_returns_true_for_admin_without_subscription(): void
    {
        $this->app['config']->set('billing.admin_bypass_method', 'isAdmin');

        $admin = new class extends Company
        {
            protected $table = 'companies';

            public function isAdmin(): bool
            {
                return true;
            }
        };
        $admin->forceFill(['id' => $this->company->id, 'name' => 'Admin Co'])->exists = true;

        // No subscription, but admin bypass should grant access
        $this->assertTrue($admin->hasFeatureOrAdmin('anything'));
        $this->assertTrue($admin->hasFeatureOrAdmin('nonexistent_feature'));
    }

    public function test_has_feature_or_admin_respects_non_admin(): void
    {
        $this->app['config']->set('billing.admin_bypass_method', 'isAdmin');

        $nonAdmin = new class extends Company
        {
            protected $table = 'companies';

            public function isAdmin(): bool
            {
                return false;
            }
        };
        $nonAdmin->forceFill(['id' => $this->company->id, 'name' => 'Normal Co'])->exists = true;

        // Not admin, no subscription → false
        $this->assertFalse($nonAdmin->hasFeatureOrAdmin('gatebook'));
    }

    // ── isBillingAdmin ────────────────────────────────────────────

    public function test_is_billing_admin_returns_false_when_not_configured(): void
    {
        $this->app['config']->set('billing.admin_bypass_method', null);

        $this->assertFalse($this->company->isBillingAdmin());
    }

    public function test_is_billing_admin_returns_false_when_method_missing(): void
    {
        $this->app['config']->set('billing.admin_bypass_method', 'isAdmin');

        // Company fixture doesn't have isAdmin()
        $this->assertFalse($this->company->isBillingAdmin());
    }

    public function test_is_billing_admin_returns_true_when_method_returns_true(): void
    {
        $this->app['config']->set('billing.admin_bypass_method', 'isAdmin');

        $admin = new class extends Company
        {
            protected $table = 'companies';

            public function isAdmin(): bool
            {
                return true;
            }
        };
        $admin->forceFill(['id' => 999, 'name' => 'Admin'])->exists = true;

        $this->assertTrue($admin->isBillingAdmin());
    }

    // ── FeatureResolver with admin bypass ─────────────────────────

    public function test_feature_resolver_bypasses_for_admin(): void
    {
        $this->app['config']->set('billing.admin_bypass_method', 'isAdmin');
        $this->app['config']->set('billing.features.cache_ttl', 0);

        $admin = new class extends Company
        {
            protected $table = 'companies';

            public function isAdmin(): bool
            {
                return true;
            }
        };
        $admin->forceFill(['id' => $this->company->id, 'name' => 'Admin Co'])->exists = true;

        $resolver = app(FeatureResolver::class);

        // No subscription, but admin → true
        $this->assertTrue($resolver->hasFeature($admin, 'any_feature'));
        $this->assertTrue($resolver->hasFeature($admin, 'nonexistent'));
    }

    public function test_feature_resolver_does_not_bypass_for_non_admin(): void
    {
        $this->app['config']->set('billing.admin_bypass_method', 'isAdmin');
        $this->app['config']->set('billing.features.cache_ttl', 0);

        $nonAdmin = new class extends Company
        {
            protected $table = 'companies';

            public function isAdmin(): bool
            {
                return false;
            }
        };
        $nonAdmin->forceFill(['id' => $this->company->id, 'name' => 'Normal Co'])->exists = true;

        $resolver = app(FeatureResolver::class);

        // Not admin, no subscription → false
        $this->assertFalse($resolver->hasFeature($nonAdmin, 'gatebook'));
    }

    // ── hasFeature still works normally ───────────────────────────

    public function test_has_feature_unchanged_for_subscribed_billable(): void
    {
        $this->app['config']->set('billing.admin_bypass_method', 'isAdmin');

        $this->company->subscribe('starter')->create();

        // Normal feature check still works
        $this->assertTrue($this->company->hasFeature('gatebook'));
        $this->assertTrue($this->company->hasFeature('incidents'));
        $this->assertFalse($this->company->hasFeature('hr_management'));
    }

    public function test_has_feature_returns_false_without_subscription(): void
    {
        $this->assertFalse($this->company->hasFeature('gatebook'));
    }
}
