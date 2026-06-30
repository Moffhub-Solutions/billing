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

class SubscriptionAddonApiTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected Plan $proPlan;

    protected Feature $ocrFeature;

    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Feature::create(['slug' => 'gatebook', 'name' => 'Gatebook', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        Feature::create(['slug' => 'shifts', 'name' => 'Shifts', 'type' => FeatureType::BOOLEAN, 'is_active' => true]);
        $this->ocrFeature = Feature::create([
            'slug' => 'ocr_scanning',
            'name' => 'OCR',
            'type' => FeatureType::METERED,
            'is_active' => true,
            'is_addon' => true,
            'addon_price' => 2000,
        ]);

        $this->proPlan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Professional',
            'slug' => 'professional',
            'base_price' => 1500000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['gatebook', 'shifts'],
            'limits' => [],
        ]);

        $this->company = Company::create(['name' => 'Addon Test Co']);
        $this->user = User::create([
            'name' => 'Addon User',
            'email' => 'addon@example.com',
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

    // ─── List Add-ons ───────────────────────────────────────────────────

    public function test_list_addons_for_subscription(): void
    {
        $this->subscription->addons()->create([
            'feature_id' => $this->ocrFeature->id,
            'status' => 'active',
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/billing/subscriptions/{$this->subscription->id}/addons");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.is_active', true);
    }

    public function test_list_addons_empty(): void
    {
        $response = $this->actingAs($this->user)->getJson("/api/billing/subscriptions/{$this->subscription->id}/addons");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ─── Enable Add-on ──────────────────────────────────────────────────

    public function test_enable_addon(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/billing/subscriptions/{$this->subscription->id}/addons", [
            'feature' => 'ocr_scanning',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', "Add-on 'OCR' enabled.")
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('billing_subscription_addons', [
            'subscription_id' => $this->subscription->id,
            'feature_id' => $this->ocrFeature->id,
            'status' => 'active',
        ]);
    }

    public function test_enable_addon_feature_not_available_as_addon(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/billing/subscriptions/{$this->subscription->id}/addons", [
            'feature' => 'gatebook', // Not an addon feature
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', "Feature 'gatebook' is not available as an add-on.");
    }

    public function test_enable_addon_already_active(): void
    {
        $this->subscription->addons()->create([
            'feature_id' => $this->ocrFeature->id,
            'status' => 'active',
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/subscriptions/{$this->subscription->id}/addons", [
            'feature' => 'ocr_scanning',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', "Add-on 'OCR' is already active on this subscription.");
    }

    public function test_enable_addon_ignores_client_supplied_price_override(): void
    {
        // A tenant must not be able to set their own add-on price. A
        // price_override in the request is ignored; the add-on falls back to the
        // feature's addon_price (a custom price is a back-office action).
        $response = $this->actingAs($this->user)->postJson("/api/billing/subscriptions/{$this->subscription->id}/addons", [
            'feature' => 'ocr_scanning',
            'price_override' => 0,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.price_override', null);

        $this->assertDatabaseHas('billing_subscription_addons', [
            'subscription_id' => $this->subscription->id,
            'feature_id' => $this->ocrFeature->id,
            'price_override' => null,
        ]);
    }

    public function test_addon_endpoints_deny_cross_tenant_access(): void
    {
        // A subscription owned by another company must be invisible.
        $otherCompany = Company::create(['name' => 'Other Co']);
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other-addon@example.com',
            'company_id' => $otherCompany->id,
        ]);

        $this->actingAs($otherUser)
            ->getJson("/api/billing/subscriptions/{$this->subscription->id}/addons")
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->postJson("/api/billing/subscriptions/{$this->subscription->id}/addons", ['feature' => 'ocr_scanning'])
            ->assertNotFound();
    }

    public function test_enable_addon_validation_requires_feature(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/billing/subscriptions/{$this->subscription->id}/addons", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['feature']);
    }

    // ─── Disable Add-on ─────────────────────────────────────────────────

    public function test_disable_addon(): void
    {
        $addon = $this->subscription->addons()->create([
            'feature_id' => $this->ocrFeature->id,
            'status' => 'active',
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/billing/subscriptions/{$this->subscription->id}/addons/{$addon->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Add-on removed.');

        $this->assertDatabaseHas('billing_subscription_addons', [
            'id' => $addon->id,
            'status' => 'cancelled',
        ]);

        $addon->refresh();
        $this->assertNotNull($addon->disabled_at);
    }

    public function test_disable_addon_not_found(): void
    {
        $response = $this->actingAs($this->user)->deleteJson("/api/billing/subscriptions/{$this->subscription->id}/addons/99999");

        $response->assertNotFound();
    }
}
