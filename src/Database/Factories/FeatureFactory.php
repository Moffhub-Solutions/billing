<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Models\Feature;

/**
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    protected $model = Feature::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'type' => FeatureType::BOOLEAN,
            'is_addon' => false,
            'is_active' => true,
            'metadata' => null,
        ];
    }

    public function metered(): static
    {
        return $this->state(['type' => FeatureType::METERED]);
    }

    public function consumable(): static
    {
        return $this->state(['type' => FeatureType::CONSUMABLE]);
    }

    public function addon(int $price = 100000): static
    {
        return $this->state([
            'is_addon' => true,
            'addon_price' => $price,
            'addon_billing_cycle' => BillingCycle::MONTHLY,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
