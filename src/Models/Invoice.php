<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Moffhub\Billing\Enums\InvoiceStatus;

class Invoice extends Model
{
    use HasFactory;

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
        return config('billing.tables.invoices', 'billing_invoices');
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

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
            && $this->due_date?->isPast();
    }

    /**
     * Get the outstanding balance (total - payments received).
     */
    public function outstandingBalance(): int
    {
        $paid = $this->payments()
            ->where('status', 'completed')
            ->sum('amount');

        return max(0, $this->total - $paid);
    }

    /**
     * Get formatted total.
     */
    public function formattedTotal(): string
    {
        return $this->currency.' '.number_format($this->total / 100, 2);
    }
}
