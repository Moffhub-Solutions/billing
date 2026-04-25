<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Services\UssdMenuBuilder;
use Moffhub\Billing\Services\UssdSessionManager;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class UssdMenuBuilderTest extends BaseTestCase
{
    protected UssdMenuBuilder $builder;

    protected UssdSessionManager $sessionManager;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionManager = $this->app()->make(UssdSessionManager::class);
        $this->builder = $this->app()->make(UssdMenuBuilder::class);
    }

    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set('billing.ussd.enabled', true);
        $config->set('billing.ussd.phone_field', 'phone');
    }

    // ─── Format Money ───────────────────────────────────────────────────

    public function test_format_money_formats_cents_to_kes(): void
    {
        $this->assertEquals('KES 7,500.00', UssdMenuBuilder::formatMoney(750000));
        $this->assertEquals('KES 0.00', UssdMenuBuilder::formatMoney(0));
        $this->assertEquals('KES 1,000.00', UssdMenuBuilder::formatMoney(100000));
        $this->assertEquals('KES 10.50', UssdMenuBuilder::formatMoney(1050));
    }

    // ─── Main Menu ──────────────────────────────────────────────────────

    public function test_empty_text_returns_main_menu(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $result = $this->builder->handle('sess1', '+254700000000', '');

        $this->assertFalse($result['is_terminal']);
        $this->assertStringContainsString('1. My Account', $result['response']);
        $this->assertStringContainsString('2. Make Payment', $result['response']);
        $this->assertStringContainsString('3. Check Usage', $result['response']);
        $this->assertStringContainsString('4. Change Plan', $result['response']);
    }

    // ─── Unlinked Phone ─────────────────────────────────────────────────

    public function test_unlinked_phone_returns_terminal_error(): void
    {
        $result = $this->builder->handle('sess1', '+254799999999', '');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('not linked', $result['response']);
    }

    // ─── My Account ─────────────────────────────────────────────────────

    public function test_my_account_with_no_subscription(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $result = $this->builder->handle('sess1', '+254700000000', '1');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('no active subscription', $result['response']);
    }

    public function test_my_account_with_active_subscription(): void
    {
        $company = Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $company->subscriptions()->create([
            'ulid' => Str::ulid()->toBase32(),
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $result = $this->builder->handle('sess1', '+254700000000', '1');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('Plan: Standard', $result['response']);
        $this->assertStringContainsString('Status: Active', $result['response']);
        $this->assertStringContainsString('KES', $result['response']);
    }

    // ─── Make Payment ───────────────────────────────────────────────────

    public function test_make_payment_asks_for_amount(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $result = $this->builder->handle('sess1', '+254700000000', '2');

        $this->assertFalse($result['is_terminal']);
        $this->assertStringContainsString('Enter amount', $result['response']);
    }

    public function test_make_payment_shows_confirmation(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $result = $this->builder->handle('sess1', '+254700000000', '2*1000');

        $this->assertFalse($result['is_terminal']);
        $this->assertStringContainsString('KES 1,000.00', $result['response']);
        $this->assertStringContainsString('1. Confirm', $result['response']);
        $this->assertStringContainsString('2. Cancel', $result['response']);
    }

    public function test_make_payment_cancel(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        // First, go through the flow so session is set
        $this->builder->handle('sess1', '+254700000000', '2*1000');

        $result = $this->builder->handle('sess1', '+254700000000', '2*1000*2');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('cancelled', $result['response']);
    }

    public function test_make_payment_invalid_amount(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $result = $this->builder->handle('sess1', '+254700000000', '2*0');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('Invalid amount', $result['response']);
    }

    // ─── Check Usage ────────────────────────────────────────────────────

    public function test_check_usage_with_no_subscription(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $result = $this->builder->handle('sess1', '+254700000000', '3');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('No active subscription', $result['response']);
    }

    public function test_check_usage_with_metered_features(): void
    {
        $company = Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
            'limits' => ['posts' => 10, 'storage' => 5],
        ]);

        $company->subscriptions()->create([
            'ulid' => Str::ulid()->toBase32(),
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $result = $this->builder->handle('sess1', '+254700000000', '3');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('Posts: 0/10', $result['response']);
        $this->assertStringContainsString('Storage: 0/5', $result['response']);
    }

    // ─── Change Plan ────────────────────────────────────────────────────

    public function test_change_plan_shows_plan_list(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $result = $this->builder->handle('sess1', '+254700000000', '4');

        $this->assertFalse($result['is_terminal']);
        $this->assertStringContainsString('Starter', $result['response']);
        $this->assertStringContainsString('Standard', $result['response']);
        $this->assertStringContainsString('KES 2,500.00', $result['response']);
        $this->assertStringContainsString('KES 7,500.00', $result['response']);
    }

    public function test_change_plan_shows_current_plan_marker(): void
    {
        $company = Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $company->subscriptions()->create([
            'ulid' => Str::ulid()->toBase32(),
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $result = $this->builder->handle('sess1', '+254700000000', '4');

        $this->assertStringContainsString('(current)', $result['response']);
    }

    public function test_change_plan_invalid_selection(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $result = $this->builder->handle('sess1', '+254700000000', '4*99');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('Invalid plan', $result['response']);
    }

    public function test_change_plan_confirmation_flow(): void
    {
        $company = Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $currentPlan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Starter',
            'slug' => 'starter',
            'base_price' => 250000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $newPlan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $company->subscriptions()->create([
            'ulid' => Str::ulid()->toBase32(),
            'plan_id' => $currentPlan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        // Select plan 2 (Standard)
        $result = $this->builder->handle('sess1', '+254700000000', '4*2');

        $this->assertFalse($result['is_terminal']);
        $this->assertStringContainsString('Standard', $result['response']);
        $this->assertStringContainsString('1. Confirm', $result['response']);

        // Confirm
        $result = $this->builder->handle('sess1', '+254700000000', '4*2*1');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('changed to Standard', $result['response']);

        // Verify subscription was updated
        $company->refresh();
        $companySubscription = $company->subscription();
        $this->assertNotNull($companySubscription);
        $this->assertEquals($newPlan->id, $companySubscription->plan_id);
    }

    // ─── Invalid Option ─────────────────────────────────────────────────

    public function test_invalid_main_menu_option(): void
    {
        Company::create(['name' => 'Test Co', 'phone' => '+254700000000']);

        $result = $this->builder->handle('sess1', '+254700000000', '9');

        $this->assertTrue($result['is_terminal']);
        $this->assertStringContainsString('Invalid option', $result['response']);
    }

    // ─── Session Manager ────────────────────────────────────────────────

    public function test_session_manager_stores_and_retrieves_data(): void
    {
        $this->sessionManager->put('test-session', ['key' => 'value']);

        $data = $this->sessionManager->get('test-session');

        $this->assertEquals(['key' => 'value'], $data);
    }

    public function test_session_manager_returns_empty_for_missing_session(): void
    {
        $data = $this->sessionManager->get('nonexistent');

        $this->assertEquals([], $data);
    }

    public function test_session_manager_updates_data(): void
    {
        $this->sessionManager->put('test-session', ['a' => 1]);
        $this->sessionManager->update('test-session', ['b' => 2]);

        $data = $this->sessionManager->get('test-session');

        $this->assertEquals(['a' => 1, 'b' => 2], $data);
    }

    public function test_session_manager_forget(): void
    {
        $this->sessionManager->put('test-session', ['key' => 'value']);
        $this->sessionManager->forget('test-session');

        $this->assertFalse($this->sessionManager->has('test-session'));
    }

    public function test_session_manager_has(): void
    {
        $this->assertFalse($this->sessionManager->has('test-session'));

        $this->sessionManager->put('test-session', ['key' => 'value']);

        $this->assertTrue($this->sessionManager->has('test-session'));
    }
}
