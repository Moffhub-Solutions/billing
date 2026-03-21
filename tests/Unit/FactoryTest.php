<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\CouponDuration;
use Moffhub\Billing\Enums\DiscountType;
use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Models\Coupon;
use Moffhub\Billing\Models\CouponRedemption;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\InvoiceItem;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\PaymentToken;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\PromotionCode;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Models\SubscriptionAddon;
use Moffhub\Billing\Models\UsageEvent;
use Moffhub\Billing\Models\UsageRecord;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class FactoryTest extends BaseTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Factory Test Co']);
    }

    // ── Plan ─────────────────────────────────────────────────────────

    public function test_plan_factory_creates_valid_model(): void
    {
        $plan = Plan::factory()->create();

        $this->assertInstanceOf(Plan::class, $plan);
        $this->assertNotNull($plan->ulid);
        $this->assertNotNull($plan->name);
        $this->assertNotNull($plan->slug);
        $this->assertTrue($plan->is_active);
        $this->assertInstanceOf(BillingCycle::class, $plan->billing_cycle);
    }

    public function test_plan_factory_inactive_state(): void
    {
        $plan = Plan::factory()->inactive()->create();

        $this->assertFalse($plan->is_active);
    }

    public function test_plan_factory_monthly_state(): void
    {
        $plan = Plan::factory()->monthly()->create();

        $this->assertEquals(BillingCycle::MONTHLY, $plan->billing_cycle);
    }

    public function test_plan_factory_annual_state(): void
    {
        $plan = Plan::factory()->annual()->create();

        $this->assertEquals(BillingCycle::ANNUAL, $plan->billing_cycle);
    }

    public function test_plan_factory_with_trial(): void
    {
        $plan = Plan::factory()->withTrial(7)->create();

        $this->assertEquals(7, $plan->trial_days);
    }

    // ── Subscription ─────────────────────────────────────────────────

    public function test_subscription_factory_creates_valid_model(): void
    {
        $subscription = Subscription::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertEquals(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->current_period_start);
        $this->assertNotNull($subscription->current_period_end);
    }

    public function test_subscription_factory_trialing_state(): void
    {
        $subscription = Subscription::factory()->trialing()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertEquals(SubscriptionStatus::TRIALING, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
    }

    public function test_subscription_factory_cancelled_state(): void
    {
        $subscription = Subscription::factory()->cancelled()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertEquals(SubscriptionStatus::CANCELLED, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
    }

    public function test_subscription_factory_paused_state(): void
    {
        $subscription = Subscription::factory()->paused()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertEquals(SubscriptionStatus::PAUSED, $subscription->status);
    }

    // ── Feature ──────────────────────────────────────────────────────

    public function test_feature_factory_creates_valid_model(): void
    {
        $feature = Feature::factory()->create();

        $this->assertInstanceOf(Feature::class, $feature);
        $this->assertEquals(FeatureType::BOOLEAN, $feature->type);
        $this->assertTrue($feature->is_active);
        $this->assertFalse($feature->is_addon);
    }

    public function test_feature_factory_metered_state(): void
    {
        $feature = Feature::factory()->metered()->create();

        $this->assertEquals(FeatureType::METERED, $feature->type);
        $this->assertTrue($feature->isTrackable());
    }

    public function test_feature_factory_consumable_state(): void
    {
        $feature = Feature::factory()->consumable()->create();

        $this->assertEquals(FeatureType::CONSUMABLE, $feature->type);
    }

    public function test_feature_factory_addon_state(): void
    {
        $feature = Feature::factory()->addon(200000)->create();

        $this->assertTrue($feature->is_addon);
        $this->assertEquals(200000, $feature->addon_price);
        $this->assertEquals(BillingCycle::MONTHLY, $feature->addon_billing_cycle);
    }

    // ── Invoice ──────────────────────────────────────────────────────

    public function test_invoice_factory_creates_valid_model(): void
    {
        $invoice = Invoice::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertEquals(InvoiceStatus::DRAFT, $invoice->status);
        $this->assertGreaterThan(0, $invoice->total);
        $this->assertEquals($invoice->subtotal + $invoice->tax_amount, $invoice->total);
    }

    public function test_invoice_factory_paid_state(): void
    {
        $invoice = Invoice::factory()->paid()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertTrue($invoice->isPaid());
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_invoice_factory_overdue_state(): void
    {
        $invoice = Invoice::factory()->overdue()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertEquals(InvoiceStatus::OVERDUE, $invoice->status);
        $this->assertTrue($invoice->due_date->isPast());
    }

    // ── InvoiceItem ──────────────────────────────────────────────────

    public function test_invoice_item_factory_creates_valid_model(): void
    {
        $invoice = Invoice::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        $this->assertInstanceOf(InvoiceItem::class, $item);
        $this->assertEquals($invoice->id, $item->invoice_id);
        $this->assertEquals($item->quantity * $item->unit_price, $item->total);
    }

    // ── Payment ──────────────────────────────────────────────────────

    public function test_payment_factory_creates_valid_model(): void
    {
        $payment = Payment::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertEquals(PaymentStatus::PENDING, $payment->status);
        $this->assertGreaterThan(0, $payment->amount);
    }

    public function test_payment_factory_completed_state(): void
    {
        $payment = Payment::factory()->completed()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertTrue($payment->isCompleted());
        $this->assertNotNull($payment->paid_at);
    }

    public function test_payment_factory_failed_state(): void
    {
        $payment = Payment::factory()->failed()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertEquals(PaymentStatus::FAILED, $payment->status);
        $this->assertNotNull($payment->failed_at);
    }

    // ── PaymentToken ─────────────────────────────────────────────────

    public function test_payment_token_factory_creates_valid_model(): void
    {
        $token = PaymentToken::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(PaymentToken::class, $token);
        $this->assertTrue($token->is_reusable);
        $this->assertTrue($token->isUsable());
    }

    public function test_payment_token_factory_expired_state(): void
    {
        $token = PaymentToken::factory()->expired()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertFalse($token->isUsable());
    }

    public function test_payment_token_factory_non_reusable_state(): void
    {
        $token = PaymentToken::factory()->nonReusable()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertFalse($token->isUsable());
    }

    public function test_payment_token_factory_card_state(): void
    {
        $token = PaymentToken::factory()->card()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertEquals('paystack', $token->provider);
        $this->assertEquals('card', $token->token_type);
    }

    // ── SubscriptionAddon ────────────────────────────────────────────

    public function test_subscription_addon_factory_creates_valid_model(): void
    {
        $subscription = Subscription::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $addon = SubscriptionAddon::factory()->create([
            'subscription_id' => $subscription->id,
        ]);

        $this->assertInstanceOf(SubscriptionAddon::class, $addon);
        $this->assertTrue($addon->isActive());
    }

    public function test_subscription_addon_factory_inactive_state(): void
    {
        $subscription = Subscription::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $addon = SubscriptionAddon::factory()->inactive()->create([
            'subscription_id' => $subscription->id,
        ]);

        $this->assertFalse($addon->isActive());
    }

    // ── UsageRecord ──────────────────────────────────────────────────

    public function test_usage_record_factory_creates_valid_model(): void
    {
        $record = UsageRecord::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(UsageRecord::class, $record);
        $this->assertNotNull($record->period_start);
        $this->assertNotNull($record->period_end);
        $this->assertTrue($record->isWithinLimit());
    }

    public function test_usage_record_factory_at_limit_state(): void
    {
        $record = UsageRecord::factory()->atLimit()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertFalse($record->isWithinLimit());
        $this->assertEquals(0, $record->remaining());
    }

    public function test_usage_record_factory_unlimited_state(): void
    {
        $record = UsageRecord::factory()->unlimited()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertNull($record->usage_limit);
        $this->assertTrue($record->isWithinLimit());
        $this->assertNull($record->remaining());
    }

    // ── UsageEvent ───────────────────────────────────────────────────

    public function test_usage_event_factory_creates_valid_model(): void
    {
        $event = UsageEvent::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(UsageEvent::class, $event);
        $this->assertGreaterThan(0, $event->quantity);
        $this->assertNotNull($event->recorded_at);
    }

    // ── Coupon ───────────────────────────────────────────────────────

    public function test_coupon_factory_creates_valid_model(): void
    {
        $coupon = Coupon::factory()->create();

        $this->assertInstanceOf(Coupon::class, $coupon);
        $this->assertEquals(DiscountType::PERCENT, $coupon->discount_type);
        $this->assertEquals(CouponDuration::ONCE, $coupon->duration);
        $this->assertTrue($coupon->is_active);
        $this->assertTrue($coupon->isRedeemable());
    }

    public function test_coupon_factory_fixed_state(): void
    {
        $coupon = Coupon::factory()->fixed(75000)->create();

        $this->assertEquals(DiscountType::FIXED, $coupon->discount_type);
        $this->assertEquals(75000, $coupon->discount_value);
    }

    public function test_coupon_factory_forever_state(): void
    {
        $coupon = Coupon::factory()->forever()->create();

        $this->assertEquals(CouponDuration::FOREVER, $coupon->duration);
    }

    public function test_coupon_factory_maxed_out_state(): void
    {
        $coupon = Coupon::factory()->maxedOut()->create();

        $this->assertFalse($coupon->isRedeemable());
    }

    public function test_coupon_factory_expired_state(): void
    {
        $coupon = Coupon::factory()->expired()->create();

        $this->assertFalse($coupon->isRedeemable());
    }

    public function test_coupon_factory_inactive_state(): void
    {
        $coupon = Coupon::factory()->inactive()->create();

        $this->assertFalse($coupon->isRedeemable());
    }

    // ── CouponRedemption ─────────────────────────────────────────────

    public function test_coupon_redemption_factory_creates_valid_model(): void
    {
        $redemption = CouponRedemption::factory()->create([
            'billable_type' => $this->company->getMorphClass(),
            'billable_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(CouponRedemption::class, $redemption);
        $this->assertNotNull($redemption->redeemed_at);
        $this->assertEquals(
            $redemption->original_amount - $redemption->discount_amount,
            $redemption->final_amount
        );
    }

    // ── PromotionCode ────────────────────────────────────────────────

    public function test_promotion_code_factory_creates_valid_model(): void
    {
        $promo = PromotionCode::factory()->create();

        $this->assertInstanceOf(PromotionCode::class, $promo);
        $this->assertTrue($promo->is_active);
        $this->assertNotNull($promo->code);
    }

    public function test_promotion_code_factory_inactive_state(): void
    {
        $promo = PromotionCode::factory()->inactive()->create();

        $this->assertFalse($promo->is_active);
    }

    public function test_promotion_code_factory_first_time_only_state(): void
    {
        $promo = PromotionCode::factory()->firstTimeOnly()->create();

        $this->assertTrue($promo->first_time_transaction);
    }

    public function test_promotion_code_factory_expired_state(): void
    {
        $promo = PromotionCode::factory()->expired()->create();

        $this->assertTrue($promo->expires_at->isPast());
    }

    public function test_promotion_code_factory_maxed_out_state(): void
    {
        $promo = PromotionCode::factory()->maxedOut()->create();

        $this->assertEquals($promo->max_redemptions, $promo->times_redeemed);
    }

    // ── Batch creation ───────────────────────────────────────────────

    public function test_can_create_multiple_models_with_factories(): void
    {
        $plans = Plan::factory()->count(3)->create();

        $this->assertCount(3, $plans);
        $this->assertEquals(3, Plan::count());
    }
}
