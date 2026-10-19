<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Database\Factories\StandingOrderExecutionFactory;

/**
 * @property int $id
 * @property int $standing_order_id
 * @property string|null $provider_transaction_id
 * @property int $amount
 * @property string $status
 * @property string|null $result_code
 * @property string|null $result_description
 * @property Carbon|null $executed_at
 * @property int|null $payment_id
 * @property array<string, mixed>|null $raw
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read StandingOrder $standingOrder
 * @property-read Payment|null $payment
 */
class StandingOrderExecution extends Model
{
    /** @use HasFactory<StandingOrderExecutionFactory> */
    use HasFactory;

    protected static function newFactory(): StandingOrderExecutionFactory
    {
        return StandingOrderExecutionFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'executed_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.standing_order_executions', 'billing_standing_order_executions');

        return is_string($value) ? $value : 'billing_standing_order_executions';
    }

    /**
     * @return BelongsTo<StandingOrder, $this>
     */
    public function standingOrder(): BelongsTo
    {
        return $this->belongsTo(StandingOrder::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
