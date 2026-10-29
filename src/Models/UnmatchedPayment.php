<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Enums\UnmatchedPaymentStatus;

/**
 * Money that arrived on the paybill and names no invoice we can use. It is kept
 * as a record, not only an event, so it is never lost and a person can allocate
 * it to the right invoice (or dismiss it) from a list.
 *
 * @property int $id
 * @property string $ulid
 * @property string $provider_payment_id
 * @property string $provider
 * @property int $amount
 * @property string $currency
 * @property string|null $account_reference
 * @property string|null $payer_phone
 * @property Carbon|null $paid_at
 * @property UnmatchedPaymentStatus $status
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_notes
 * @property int|null $payment_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class UnmatchedPayment extends Model
{
    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => UnmatchedPaymentStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.unmatched_payments', 'billing_unmatched_payments');

        return is_string($value) ? $value : 'billing_unmatched_payments';
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === UnmatchedPaymentStatus::OPEN;
    }
}
