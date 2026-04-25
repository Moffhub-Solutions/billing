<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Database\Factories\PaymentFactory;
use Moffhub\Billing\Enums\PaymentMethod;
use Moffhub\Billing\Enums\PaymentStatus;

/**
 * @property int $id
 * @property string $ulid
 * @property string $billable_type
 * @property int $billable_id
 * @property int|null $subscription_id
 * @property int|null $invoice_id
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $payment_provider
 * @property string|null $provider_payment_id
 * @property string|null $provider_reference
 * @property PaymentMethod|null $payment_method
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $refunded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model|null $billable
 * @property-read Subscription|null $subscription
 * @property-read Invoice|null $invoice
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.payments', 'billing_payments');

        return is_string($value) ? $value : 'billing_payments';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING;
    }

    /**
     * Get formatted amount (e.g., "KES 5,000.00").
     */
    public function formattedAmount(): string
    {
        return $this->currency.' '.number_format($this->amount / 100, 2);
    }
}
