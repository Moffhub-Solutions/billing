<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Models\SubscriptionAddon;

/**
 * @extends Factory<SubscriptionAddon>
 */
class SubscriptionAddonFactory extends Factory
{
    protected $model = SubscriptionAddon::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'feature_id' => Feature::factory(),
            'status' => 'active',
            'enabled_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state([
            'status' => 'inactive',
            'disabled_at' => now(),
        ]);
    }

    public function withPriceOverride(int $price): static
    {
        return $this->state(['price_override' => $price]);
    }
}
