<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * @property string|null $payment_group
 * @property int|null $group_sequence
 * @property int|null $group_size
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
            'group_sequence' => 'integer',
            'group_size' => 'integer',
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

    // ─── Split payment groups ──────────────────────────────────────────

    /**
     * Whether this payment is one tranche of a split (grouped) payment.
     */
    public function isSplit(): bool
    {
        return $this->payment_group !== null;
    }

    /**
     * All tranche payments in this payment's group, ordered by sequence.
     * An unsplit payment resolves to just itself.
     *
     * @return Collection<int, static>
     */
    public function groupTranches(): Collection
    {
        $query = static::query();

        if ($this->payment_group === null) {
            $query->whereKey($this->getKey());
        } else {
            $query->where('payment_group', $this->payment_group)->orderBy('group_sequence');
        }

        return $query->get();
    }

    /**
     * The next pending tranche in this group (for sequential collection), if any.
     */
    public function nextPendingTranche(): ?Payment
    {
        if ($this->payment_group === null) {
            return null;
        }

        return static::query()
            ->where('payment_group', $this->payment_group)
            ->where('status', PaymentStatus::PENDING)
            ->orderBy('group_sequence')
            ->first();
    }

    /**
     * Whether every tranche in this payment's group has completed.
     */
    public function groupIsComplete(): bool
    {
        if ($this->payment_group === null) {
            return $this->isCompleted();
        }

        return ! static::query()
            ->where('payment_group', $this->payment_group)
            ->where('status', '!=', PaymentStatus::COMPLETED->value)
            ->exists();
    }

    /**
     * Restrict a query to tranches of the given group.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeForGroup(Builder $query, string $group): Builder
    {
        return $query->where('payment_group', $group);
    }
}
