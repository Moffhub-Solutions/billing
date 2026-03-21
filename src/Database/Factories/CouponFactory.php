<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\CouponDuration;
use Moffhub\Billing\Enums\DiscountType;
use Moffhub\Billing\Models\Coupon;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'ulid' => Str::ulid()->toBase32(),
            'name' => fake()->words(2, true).' Discount',
            'discount_type' => DiscountType::PERCENT,
            'discount_value' => fake()->randomElement([10, 15, 20, 25, 50]),
            'duration' => CouponDuration::ONCE,
            'is_active' => true,
            'times_redeemed' => 0,
            'metadata' => null,
        ];
    }

    public function fixed(int $amount = 50000): static
    {
        return $this->state([
            'discount_type' => DiscountType::FIXED,
            'discount_value' => $amount,
        ]);
    }

    public function forever(): static
    {
        return $this->state(['duration' => CouponDuration::FOREVER]);
    }

    public function repeating(int $months = 3): static
    {
        return $this->state([
            'duration' => CouponDuration::REPEATING,
            'duration_in_months' => $months,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function maxedOut(): static
    {
        return $this->state([
            'max_redemptions' => 10,
            'times_redeemed' => 10,
        ]);
    }

    public function expired(): static
    {
        return $this->state(['redeem_by' => now()->subDay()]);
    }
}
