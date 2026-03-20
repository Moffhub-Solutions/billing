<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\CouponDuration;
use Moffhub\Billing\Enums\DiscountType;
use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\PaymentMethod;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    // BillingCycle tests

    public function test_billing_cycle_monthly_days(): void
    {
        $this->assertEquals(30, BillingCycle::MONTHLY->days());
    }

    public function test_billing_cycle_quarterly_days(): void
    {
        $this->assertEquals(90, BillingCycle::QUARTERLY->days());
    }

    public function test_billing_cycle_annual_days(): void
    {
        $this->assertEquals(365, BillingCycle::ANNUAL->days());
    }

    public function test_billing_cycle_labels(): void
    {
        $this->assertEquals('Monthly', BillingCycle::MONTHLY->label());
        $this->assertEquals('Quarterly', BillingCycle::QUARTERLY->label());
        $this->assertEquals('Annual', BillingCycle::ANNUAL->label());
    }

    public function test_billing_cycle_values(): void
    {
        $this->assertEquals('monthly', BillingCycle::MONTHLY->value);
        $this->assertEquals('quarterly', BillingCycle::QUARTERLY->value);
        $this->assertEquals('annual', BillingCycle::ANNUAL->value);
    }

    // SubscriptionStatus tests

    public function test_subscription_status_is_active(): void
    {
        $this->assertTrue(SubscriptionStatus::ACTIVE->isActive());
        $this->assertTrue(SubscriptionStatus::TRIALING->isActive());
        $this->assertFalse(SubscriptionStatus::PAST_DUE->isActive());
        $this->assertFalse(SubscriptionStatus::CANCELLED->isActive());
        $this->assertFalse(SubscriptionStatus::PAUSED->isActive());
        $this->assertFalse(SubscriptionStatus::EXPIRED->isActive());
    }

    public function test_subscription_status_labels(): void
    {
        $this->assertEquals('Active', SubscriptionStatus::ACTIVE->label());
        $this->assertEquals('Trialing', SubscriptionStatus::TRIALING->label());
        $this->assertEquals('Past Due', SubscriptionStatus::PAST_DUE->label());
        $this->assertEquals('Cancelled', SubscriptionStatus::CANCELLED->label());
        $this->assertEquals('Paused', SubscriptionStatus::PAUSED->label());
        $this->assertEquals('Expired', SubscriptionStatus::EXPIRED->label());
    }

    public function test_subscription_status_values(): void
    {
        $this->assertEquals('active', SubscriptionStatus::ACTIVE->value);
        $this->assertEquals('trialing', SubscriptionStatus::TRIALING->value);
        $this->assertEquals('past_due', SubscriptionStatus::PAST_DUE->value);
        $this->assertEquals('cancelled', SubscriptionStatus::CANCELLED->value);
        $this->assertEquals('paused', SubscriptionStatus::PAUSED->value);
        $this->assertEquals('expired', SubscriptionStatus::EXPIRED->value);
    }

    // FeatureType tests

    public function test_feature_type_is_trackable(): void
    {
        $this->assertFalse(FeatureType::BOOLEAN->isTrackable());
        $this->assertTrue(FeatureType::METERED->isTrackable());
        $this->assertTrue(FeatureType::CONSUMABLE->isTrackable());
    }

    public function test_feature_type_labels(): void
    {
        $this->assertEquals('Boolean (on/off)', FeatureType::BOOLEAN->label());
        $this->assertEquals('Metered (resets each period)', FeatureType::METERED->label());
        $this->assertEquals('Consumable (one-time allowance)', FeatureType::CONSUMABLE->label());
    }

    public function test_feature_type_values(): void
    {
        $this->assertEquals('boolean', FeatureType::BOOLEAN->value);
        $this->assertEquals('metered', FeatureType::METERED->value);
        $this->assertEquals('consumable', FeatureType::CONSUMABLE->value);
    }

    // PaymentStatus tests

    public function test_payment_status_labels(): void
    {
        $this->assertEquals('Pending', PaymentStatus::PENDING->label());
        $this->assertEquals('Completed', PaymentStatus::COMPLETED->label());
        $this->assertEquals('Failed', PaymentStatus::FAILED->label());
        $this->assertEquals('Refunded', PaymentStatus::REFUNDED->label());
    }

    public function test_payment_status_values(): void
    {
        $this->assertEquals('pending', PaymentStatus::PENDING->value);
        $this->assertEquals('completed', PaymentStatus::COMPLETED->value);
        $this->assertEquals('failed', PaymentStatus::FAILED->value);
        $this->assertEquals('refunded', PaymentStatus::REFUNDED->value);
    }

    // InvoiceStatus tests

    public function test_invoice_status_labels(): void
    {
        $this->assertEquals('Draft', InvoiceStatus::DRAFT->label());
        $this->assertEquals('Sent', InvoiceStatus::SENT->label());
        $this->assertEquals('Paid', InvoiceStatus::PAID->label());
        $this->assertEquals('Partially Paid', InvoiceStatus::PARTIALLY_PAID->label());
        $this->assertEquals('Overdue', InvoiceStatus::OVERDUE->label());
        $this->assertEquals('Void', InvoiceStatus::VOID->label());
    }

    public function test_invoice_status_values(): void
    {
        $this->assertEquals('draft', InvoiceStatus::DRAFT->value);
        $this->assertEquals('sent', InvoiceStatus::SENT->value);
        $this->assertEquals('paid', InvoiceStatus::PAID->value);
        $this->assertEquals('partially_paid', InvoiceStatus::PARTIALLY_PAID->value);
        $this->assertEquals('overdue', InvoiceStatus::OVERDUE->value);
        $this->assertEquals('void', InvoiceStatus::VOID->value);
    }

    // PaymentMethod tests

    public function test_payment_method_labels(): void
    {
        $this->assertEquals('M-Pesa', PaymentMethod::MPESA->label());
        $this->assertEquals('Card', PaymentMethod::CARD->label());
        $this->assertEquals('Bank Transfer', PaymentMethod::BANK->label());
        $this->assertEquals('Mobile Money', PaymentMethod::MOBILE_MONEY->label());
        $this->assertEquals('Manual/Cash', PaymentMethod::MANUAL->label());
    }

    public function test_payment_method_values(): void
    {
        $this->assertEquals('mpesa', PaymentMethod::MPESA->value);
        $this->assertEquals('card', PaymentMethod::CARD->value);
        $this->assertEquals('bank', PaymentMethod::BANK->value);
        $this->assertEquals('mobile_money', PaymentMethod::MOBILE_MONEY->value);
        $this->assertEquals('manual', PaymentMethod::MANUAL->value);
    }

    // CouponDuration tests

    public function test_coupon_duration_labels(): void
    {
        $this->assertEquals('Once', CouponDuration::ONCE->label());
        $this->assertEquals('Multiple Months', CouponDuration::REPEATING->label());
        $this->assertEquals('Forever', CouponDuration::FOREVER->label());
    }

    public function test_coupon_duration_values(): void
    {
        $this->assertEquals('once', CouponDuration::ONCE->value);
        $this->assertEquals('repeating', CouponDuration::REPEATING->value);
        $this->assertEquals('forever', CouponDuration::FOREVER->value);
    }

    // DiscountType tests

    public function test_discount_type_labels(): void
    {
        $this->assertEquals('Percentage', DiscountType::PERCENT->label());
        $this->assertEquals('Fixed Amount', DiscountType::FIXED->label());
    }

    public function test_discount_type_values(): void
    {
        $this->assertEquals('percent', DiscountType::PERCENT->value);
        $this->assertEquals('fixed', DiscountType::FIXED->value);
    }
}
