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

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $originalAmountValue = fake()->randomElement([250000, 500000, 750000]);
        $originalAmount = is_int($originalAmountValue) ? $originalAmountValue : 250000;
        $discountAmount = (int) round($originalAmount * 0.2);

        $billableModelRaw = config('billing.billable_model', 'App\\Models\\Company');
        $billableModel = is_string($billableModelRaw) ? $billableModelRaw : 'App\\Models\\Company';

        return [
            'billable_type' => $billableModel,
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
