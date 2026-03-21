<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Models\Plan;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'ulid' => Str::ulid()->toBase32(),
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'base_price' => fake()->randomElement([250000, 500000, 750000, 1000000]),
            'currency' => 'KES',
            'billing_cycle' => fake()->randomElement(BillingCycle::cases()),
            'trial_days' => 0,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 10),
            'features' => ['gatebook', 'incidents'],
            'limits' => ['max_posts' => 5],
            'metadata' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function monthly(): static
    {
        return $this->state(['billing_cycle' => BillingCycle::MONTHLY]);
    }

    public function annual(): static
    {
        return $this->state(['billing_cycle' => BillingCycle::ANNUAL]);
    }

    public function withTrial(int $days = 14): static
    {
        return $this->state(['trial_days' => $days]);
    }
}
