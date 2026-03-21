<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Models\Coupon;
use Moffhub\Billing\Models\PromotionCode;

/**
 * @extends Factory<PromotionCode>
 */
class PromotionCodeFactory extends Factory
{
    protected $model = PromotionCode::class;

    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory(),
            'code' => strtoupper(Str::random(10)),
            'is_active' => true,
            'first_time_transaction' => false,
            'times_redeemed' => 0,
            'metadata' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function firstTimeOnly(): static
    {
        return $this->state(['first_time_transaction' => true]);
    }

    public function withMinimumAmount(int $amount): static
    {
        return $this->state(['minimum_amount' => $amount]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function maxedOut(): static
    {
        return $this->state([
            'max_redemptions' => 5,
            'times_redeemed' => 5,
        ]);
    }
}
