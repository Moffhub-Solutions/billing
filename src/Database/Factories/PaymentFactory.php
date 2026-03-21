<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\PaymentMethod;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Models\Payment;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => config('billing.billable_model', 'App\\Models\\Company'),
            'billable_id' => 1,
            'amount' => fake()->randomElement([100000, 250000, 500000, 750000]),
            'currency' => 'KES',
            'status' => PaymentStatus::PENDING,
            'payment_provider' => 'manual',
            'metadata' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status' => PaymentStatus::COMPLETED,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => PaymentStatus::FAILED,
            'failed_at' => now(),
        ]);
    }

    public function refunded(): static
    {
        return $this->state([
            'status' => PaymentStatus::REFUNDED,
            'refunded_at' => now(),
        ]);
    }

    public function mpesa(): static
    {
        return $this->state([
            'payment_method' => PaymentMethod::MPESA,
            'payment_provider' => 'mpesa',
        ]);
    }
}
