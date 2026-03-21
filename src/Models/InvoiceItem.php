<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Moffhub\Billing\Database\Factories\InvoiceItemFactory;

class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    protected static function newFactory(): InvoiceItemFactory
    {
        return InvoiceItemFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'total' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return config('billing.tables.invoice_items', 'billing_invoice_items');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
