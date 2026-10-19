<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\StandingOrderFrequency;
use Moffhub\Billing\Enums\StandingOrderStatus;
use Moffhub\Billing\Models\StandingOrder;

/**
 * @extends Factory<StandingOrder>
 */
class StandingOrderFactory extends Factory
{
    protected $model = StandingOrder::class;

    public function definition(): array
    {
        return [
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => config('billing.billable_model', 'App\\Models\\Company'),
            'billable_id' => 1,
            'provider' => 'mpesa',
            'provider_reference' => 'ref-'.Str::lower(Str::random(10)),
            'account_reference' => Str::upper(Str::random(10)),
            'name' => 'Plan '.Str::random(6),
            'amount' => 500000,
            'currency' => 'KES',
            'frequency' => StandingOrderFrequency::Monthly,
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'status' => StandingOrderStatus::Pending,
            'customer_phone' => '254712345678',
            'customer_phone_hash' => hash('sha256', '254712345678'),
            'customer_phone_last4' => '5678',
            'consent_at' => now(),
            'failure_count' => 0,
            'metadata' => null,
        ];
    }

    public function active(): static
    {
        return $this->state([
            'status' => StandingOrderStatus::Active,
            'next_expected_at' => now()->addMonth(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => StandingOrderStatus::Cancelled]);
    }
}
