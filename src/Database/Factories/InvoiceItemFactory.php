<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\InvoiceItem;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomElement([100000, 250000, 500000]);

        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->sentence(3),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $quantity * $unitPrice,
            'metadata' => null,
        ];
    }
}
