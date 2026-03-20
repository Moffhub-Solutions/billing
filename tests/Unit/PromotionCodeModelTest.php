<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Models\Coupon;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\PromotionCode;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class PromotionCodeModelTest extends BaseTestCase
{
    protected Coupon $coupon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => '20% Off',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'duration' => 'once',
            'is_active' => true,
        ]);
    }

    public function test_belongs_to_coupon(): void
    {
        $promo = PromotionCode::create([
            'code' => 'SAVE20',
            'coupon_id' => $this->coupon->id,
            'is_active' => true,
        ]);

        $this->assertNotNull($promo->coupon);
        $this->assertEquals($this->coupon->id, $promo->coupon->id);
    }

    public function test_is_redeemable(): void
    {
        $promo = PromotionCode::create([
            'code' => 'ACTIVE20',
            'coupon_id' => $this->coupon->id,
            'is_active' => true,
        ]);

        $this->assertTrue($promo->isRedeemable());
    }

    public function test_expired(): void
    {
        $promo = PromotionCode::create([
            'code' => 'EXPIRED20',
            'coupon_id' => $this->coupon->id,
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($promo->isRedeemable());
    }

    public function test_max_reached(): void
    {
        $promo = PromotionCode::create([
            'code' => 'MAXED20',
            'coupon_id' => $this->coupon->id,
            'is_active' => true,
            'max_redemptions' => 5,
            'times_redeemed' => 5,
        ]);

        $this->assertFalse($promo->isRedeemable());
    }

    public function test_validate_restrictions_first_time(): void
    {
        $company = Company::create(['name' => 'Test Co']);

        $promo = PromotionCode::create([
            'code' => 'FIRST20',
            'coupon_id' => $this->coupon->id,
            'is_active' => true,
            'first_time_transaction' => true,
        ]);

        // No previous payments - should pass
        $errors = $promo->validateRestrictions($company, 100000);
        $this->assertEmpty($errors);

        // Create a completed payment
        Payment::create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $company->getMorphClass(),
            'billable_id' => $company->id,
            'amount' => 50000,
            'currency' => 'KES',
            'status' => 'completed',
        ]);

        // Now should fail
        $errors = $promo->validateRestrictions($company, 100000);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('first-time', $errors[0]);
    }

    public function test_validate_restrictions_minimum(): void
    {
        $company = Company::create(['name' => 'Test Co']);

        $promo = PromotionCode::create([
            'code' => 'MIN20',
            'coupon_id' => $this->coupon->id,
            'is_active' => true,
            'minimum_amount' => 500000, // KES 5,000
        ]);

        // Below minimum
        $errors = $promo->validateRestrictions($company, 200000);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Minimum', $errors[0]);

        // Above minimum
        $errors = $promo->validateRestrictions($company, 600000);
        $this->assertEmpty($errors);
    }

    public function test_find_by_code(): void
    {
        PromotionCode::create([
            'code' => 'FINDME',
            'coupon_id' => $this->coupon->id,
            'is_active' => true,
        ]);

        $found = PromotionCode::findByCode('FINDME');
        $this->assertNotNull($found);
        $this->assertEquals('FINDME', $found->code);

        $notFound = PromotionCode::findByCode('NOPE');
        $this->assertNull($notFound);
    }

    public function test_find_by_code_case_insensitive(): void
    {
        PromotionCode::create([
            'code' => 'SAVE50',
            'coupon_id' => $this->coupon->id,
            'is_active' => true,
        ]);

        // findByCode uppercases the input
        $found = PromotionCode::findByCode('save50');
        $this->assertNotNull($found);
        $this->assertEquals('SAVE50', $found->code);
    }
}
