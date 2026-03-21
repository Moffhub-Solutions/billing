<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Models\UsageEvent;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => config('billing.billable_model', 'App\\Models\\Company'),
            'billable_id' => 1,
            'feature_slug' => fake()->slug(2),
            'quantity' => fake()->numberBetween(1, 10),
            'recorded_at' => now(),
            'properties' => null,
        ];
    }
}
