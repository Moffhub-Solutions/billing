<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\CouponDuration;
use Moffhub\Billing\Enums\DiscountType;
use Moffhub\Billing\Exceptions\CouponException;
use Moffhub\Billing\Models\Coupon;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\PromotionCode;
use Moffhub\Billing\Services\CouponService;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class CouponTest extends BaseTestCase
{
    protected CouponService $couponService;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->couponService = app(CouponService::class);

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['gatebook'],
        ]);

        $this->company = Company::create(['name' => 'Test Co']);
    }

    public function test_create_percent_coupon(): void
    {
        $coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => '20% Off',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => 20,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
        ]);

        $this->assertEquals(20, $coupon->discount_value);
        $this->assertEquals(DiscountType::PERCENT, $coupon->discount_type);
        $this->assertEquals('20% off', $coupon->discountDescription());
    }

    public function test_create_fixed_coupon(): void
    {
        $coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'KES 500 Off',
            'discount_type' => DiscountType::FIXED,
            'discount_value' => 50000, // KES 500
            'currency' => 'KES',
            'duration' => CouponDuration::FOREVER,
            'is_active' => true,
        ]);

        $this->assertEquals(50000, $coupon->calculateDiscount(750000));
    }

    public function test_percent_discount_calculation(): void
    {
        $coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => '25% Off',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => 25,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
        ]);

        $this->assertEquals(187500, $coupon->calculateDiscount(750000)); // 25% of 7500
    }

    public function test_fixed_discount_cannot_exceed_amount(): void
    {
        $coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'KES 10,000 Off',
            'discount_type' => DiscountType::FIXED,
            'discount_value' => 1000000,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
        ]);

        // Discount capped at the amount
        $this->assertEquals(750000, $coupon->calculateDiscount(750000));
    }

    public function test_coupon_redeemability(): void
    {
        $active = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Active',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => 10,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
        ]);

        $expired = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Expired',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => 10,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
            'redeem_by' => now()->subDay(),
        ]);

        $maxed = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Maxed',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => 10,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
            'max_redemptions' => 5,
            'times_redeemed' => 5,
        ]);

        $this->assertTrue($active->isRedeemable());
        $this->assertFalse($expired->isRedeemable());
        $this->assertFalse($maxed->isRedeemable());
    }

    public function test_promotion_code_redemption(): void
    {
        $coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => '30% Off',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => 30,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
        ]);

        PromotionCode::create([
            'code' => 'SAVE30',
            'coupon_id' => $coupon->id,
            'is_active' => true,
        ]);

        $result = $this->couponService->applyCode('SAVE30', $this->company, 750000);

        $this->assertEquals(225000, $result['discount_amount']); // 30% of 750000
        $this->assertEquals(525000, $result['final_amount']);
        $this->assertEquals(1, $coupon->fresh()->times_redeemed);
    }

    public function test_invalid_promotion_code_throws(): void
    {
        $this->expectException(CouponException::class);

        $this->couponService->applyCode('INVALID', $this->company, 750000);
    }

    public function test_preview_code(): void
    {
        $coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => '15% Off',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => 15,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
        ]);

        PromotionCode::create([
            'code' => 'PREVIEW15',
            'coupon_id' => $coupon->id,
            'is_active' => true,
        ]);

        $result = $this->couponService->preview('PREVIEW15', $this->company, 1000000);

        $this->assertTrue($result['valid']);
        $this->assertEquals(150000, $result['discount_amount']);
        $this->assertEquals(850000, $result['final_amount']);
        // Preview should NOT increment counters
        $this->assertEquals(0, $coupon->fresh()->times_redeemed);
    }

    public function test_plan_restriction(): void
    {
        $coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Pro Only',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => 50,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
            'applies_to_plans' => ['professional'],
        ]);

        $this->assertTrue($coupon->appliesToPlan('professional'));
        $this->assertFalse($coupon->appliesToPlan('standard'));
    }

    public function test_coupon_applies_to_all_plans_when_null(): void
    {
        $coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Universal',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => 10,
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
            'applies_to_plans' => null,
        ]);

        $this->assertTrue($coupon->appliesToPlan('standard'));
        $this->assertTrue($coupon->appliesToPlan('professional'));
        $this->assertTrue($coupon->appliesToPlan('anything'));
    }
}
