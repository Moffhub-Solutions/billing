<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Database\Factories\InvoiceFactory;
use Moffhub\Billing\Enums\InvoiceStatus;

/**
 * @property int $id
 * @property string $ulid
 * @property string $billable_type
 * @property int $billable_id
 * @property int|null $subscription_id
 * @property string $number
 * @property InvoiceStatus $status
 * @property int $subtotal
 * @property int $tax_amount
 * @property int $total
 * @property string $currency
 * @property float $tax_rate
 * @property Carbon|null $due_date
 * @property Carbon|null $paid_at
 * @property array<string, mixed>|null $metadata
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model $billable
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Collection<int, Payment> $payments
 *
 * @method static Builder<self> creditNotes()
 * @method static Builder<self> regularInvoices()
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'tax_rate' => 'float',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.invoices', 'billing_invoices');

        return is_string($value) ? $value : 'billing_invoices';
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
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::PAID;
    }

    public function isOverdue(): bool
    {
        return $this->status !== InvoiceStatus::PAID
            && $this->status !== InvoiceStatus::VOID
            && ($this->due_date?->isPast() ?? false);
    }

    /**
     * Get the outstanding balance (total - payments received).
     */
    public function outstandingBalance(): int
    {
        return max(0, $this->total - $this->amountPaid());
    }

    /**
     * Total of completed payments against this invoice (in cents).
     *
     * Only payments in the invoice's own currency count: amounts in different
     * currencies are not fungible, so a payment in another currency must never
     * settle (or partially settle) this invoice.
     */
    public function amountPaid(): int
    {
        return (int) $this->payments()
            ->where('status', 'completed')
            ->where('currency', $this->currency)
            ->sum('amount');
    }

    /**
     * Re-derive the invoice status from its completed payments.
     *
     * Moves the invoice to PAID once fully settled (stamping paid_at), or
     * PARTIALLY_PAID while some, but not all, of the balance is covered. Used
     * when split-payment tranches settle one tranche at a time. Terminal
     * states (PAID, VOID) and credit notes are left untouched.
     */
    public function recalculateStatus(): void
    {
        if ($this->status === InvoiceStatus::PAID || $this->status === InvoiceStatus::VOID || $this->isCreditNote()) {
            return;
        }

        $paid = $this->amountPaid();

        if ($paid >= $this->total && $this->total > 0) {
            $this->status = InvoiceStatus::PAID;
            $this->paid_at = $this->paid_at ?? now();
        } elseif ($paid > 0) {
            $this->status = InvoiceStatus::PARTIALLY_PAID;
        } else {
            return;
        }

        $this->save();
    }

    /**
     * Get formatted total.
     */
    public function formattedTotal(): string
    {
        return $this->currency.' '.number_format($this->total / 100, 2);
    }

    /**
     * Check if this invoice is a credit note (negative total).
     */
    public function isCreditNote(): bool
    {
        return $this->total < 0;
    }

    /**
     * Get the original invoice if this is a credit note.
     */
    public function creditNoteFor(): ?self
    {
        $metadata = $this->metadata ?? [];

        if (! isset($metadata['original_invoice_id'])) {
            return null;
        }

        $originalId = $metadata['original_invoice_id'];

        if (! is_int($originalId) && ! is_string($originalId)) {
            return null;
        }

        return self::find($originalId);
    }

    /**
     * Scope to only credit notes (negative total).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCreditNotes(Builder $query): Builder
    {
        return $query->where('total', '<', 0);
    }

    /**
     * Scope to only regular invoices (non-negative total).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRegularInvoices(Builder $query): Builder
    {
        return $query->where('total', '>=', 0);
    }
}
