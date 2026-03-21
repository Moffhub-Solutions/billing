<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\SubscriptionStatus;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => config('billing.billable_model', 'App\\Models\\Company'),
            'billable_id' => 1,
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
            'metadata' => null,
        ];
    }

    public function trialing(int $days = 14): static
    {
        return $this->state([
            'status' => SubscriptionStatus::TRIALING,
            'trial_ends_at' => now()->addDays($days),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function paused(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::PAUSED,
            'paused_at' => now(),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::PAST_DUE,
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::EXPIRED,
            'current_period_end' => now()->subDay(),
        ]);
    }
}
