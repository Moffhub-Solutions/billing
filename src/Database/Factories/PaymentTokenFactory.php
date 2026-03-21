<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Models\PaymentToken;

/**
 * @extends Factory<PaymentToken>
 */
class PaymentTokenFactory extends Factory
{
    protected $model = PaymentToken::class;

    public function definition(): array
    {
        return [
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => config('billing.billable_model', 'App\\Models\\Company'),
            'billable_id' => 1,
            'provider' => 'mpesa',
            'token_type' => 'phone',
            'token' => fake()->e164PhoneNumber(),
            'last_four' => (string) fake()->numberBetween(1000, 9999),
            'phone' => fake()->e164PhoneNumber(),
            'is_default' => false,
            'is_reusable' => true,
            'metadata' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function nonReusable(): static
    {
        return $this->state(['is_reusable' => false]);
    }

    public function card(): static
    {
        return $this->state([
            'provider' => 'paystack',
            'token_type' => 'card',
            'card_brand' => 'Visa',
            'card_exp_month' => '12',
            'card_exp_year' => '2028',
            'phone' => null,
        ]);
    }
}
