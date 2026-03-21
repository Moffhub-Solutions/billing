<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Models\Invoice;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal = fake()->randomElement([100000, 250000, 500000, 750000]);
        $taxRate = 16.0;
        $taxAmount = (int) round($subtotal * ($taxRate / 100));

        return [
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => config('billing.billable_model', 'App\\Models\\Company'),
            'billable_id' => 1,
            'number' => 'INV-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => InvoiceStatus::DRAFT,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'tax_rate' => $taxRate,
            'total' => $subtotal + $taxAmount,
            'currency' => 'KES',
            'due_date' => now()->addDays(30),
            'metadata' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
        ]);
    }

    public function sent(): static
    {
        return $this->state(['status' => InvoiceStatus::SENT]);
    }

    public function overdue(): static
    {
        return $this->state([
            'status' => InvoiceStatus::OVERDUE,
            'due_date' => now()->subDays(7),
        ]);
    }

    public function void(): static
    {
        return $this->state(['status' => InvoiceStatus::VOID]);
    }
}
