<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Models\UsageRecord;

/**
 * @extends Factory<UsageRecord>
 */
class UsageRecordFactory extends Factory
{
    protected $model = UsageRecord::class;

    public function definition(): array
    {
        return [
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => config('billing.billable_model', 'App\\Models\\Company'),
            'billable_id' => 1,
            'feature_slug' => fake()->slug(2),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'usage_count' => fake()->numberBetween(0, 50),
            'usage_limit' => 100,
            'overage_count' => 0,
            'metadata' => null,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(['usage_limit' => null]);
    }

    public function atLimit(): static
    {
        return $this->state(fn (array $attributes) => [
            'usage_count' => $attributes['usage_limit'] ?? 100,
        ]);
    }

    public function overLimit(): static
    {
        return $this->state(fn (array $attributes) => [
            'usage_count' => ($attributes['usage_limit'] ?? 100) + 10,
            'overage_count' => 10,
        ]);
    }
}
