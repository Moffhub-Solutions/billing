<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Moffhub\Billing\Models\Coupon;
use Moffhub\Billing\Models\CouponRedemption;

/**
 * @extends Factory<CouponRedemption>
 */
class CouponRedemptionFactory extends Factory
{
    protected $model = CouponRedemption::class;

    public function definition(): array
    {
        $originalAmount = fake()->randomElement([250000, 500000, 750000]);
        $discountAmount = (int) round($originalAmount * 0.2);

        return [
            'billable_type' => config('billing.billable_model', 'App\\Models\\Company'),
            'billable_id' => 1,
            'coupon_id' => Coupon::factory(),
            'original_amount' => $originalAmount,
            'discount_amount' => $discountAmount,
            'final_amount' => $originalAmount - $discountAmount,
            'redeemed_at' => now(),
            'metadata' => null,
        ];
    }
}
