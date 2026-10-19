<?php

declare(strict_types=1);

namespace Moffhub\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Moffhub\Billing\Models\StandingOrder;
use Moffhub\Billing\Models\StandingOrderExecution;

/**
 * @extends Factory<StandingOrderExecution>
 */
class StandingOrderExecutionFactory extends Factory
{
    protected $model = StandingOrderExecution::class;

    public function definition(): array
    {
        return [
            'standing_order_id' => StandingOrder::factory(),
            'provider_transaction_id' => Str::upper(Str::random(10)),
            'amount' => 500000,
            'status' => 'completed',
            'result_code' => '0',
            'result_description' => 'Success',
            'executed_at' => now(),
            'raw' => null,
        ];
    }
}
