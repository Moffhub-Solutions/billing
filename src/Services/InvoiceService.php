<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\TaxCalculatorInterface;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Events\InvoiceGenerated;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Subscription;

class InvoiceService
{
    public function __construct(
        protected TaxCalculatorInterface $taxCalculator,
    ) {}

    /**
     * Generate an invoice for a subscription's current billing period.
     */
    public function generateForSubscription(Subscription $subscription): Invoice
    {
        $plan = $subscription->plan;
        $billable = $subscription->billable;
        $currencyDefault = billing_setting('currency', 'KES', $billable);
        $currency = $plan->currency ?? (is_string($currencyDefault) ? $currencyDefault : 'KES');

        // Calculate plan line item
        $subtotal = $plan->base_price;
        $items = [];

        $items[] = [
            'description' => $plan->name.' - '.$plan->billing_cycle->label(),
            'quantity' => 1,
            'unit_price' => $plan->base_price,
            'total' => $plan->base_price,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
        ];

        // Add active add-ons
        $activeAddons = $subscription->addons()->where('status', 'active')->with('feature')->get();

        foreach ($activeAddons as $addon) {
            $addonPrice = $addon->price_override ?? $addon->feature->addon_price ?? 0;
            $subtotal += $addonPrice;

            $items[] = [
                'description' => 'Add-on: '.($addon->feature->name ?? 'Unknown'),
                'quantity' => 1,
                'unit_price' => $addonPrice,
                'total' => $addonPrice,
                'feature_slug' => $addon->feature->slug ?? null,
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
            ];
        }

        // Calculate tax
        $taxResult = $this->taxCalculator->calculate($subtotal, $currency);
        $taxAmount = $taxResult['tax_amount'];
        $taxRate = $taxResult['tax_rate'];
        $total = $subtotal + $taxAmount;

        // Create the invoice
        $invoice = new Invoice([
            'ulid' => Str::ulid()->toBase32(),
            'number' => $this->generateInvoiceNumber(),
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::DRAFT,
            'currency' => $currency,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'tax_rate' => $taxRate,
            'total' => $total,
            'due_date' => now()->addDays($this->dueDays($billable)),
            'metadata' => [
                'tax_breakdown' => $taxResult['breakdown'],
                'tax_label' => $taxResult['tax_label'],
            ],
        ]);

        $subscription->billable->morphMany(Invoice::class, 'billable')->save($invoice);

        // Create line items
        foreach ($items as $item) {
            $invoice->items()->create($item);
        }

        $invoice->load('items');

        InvoiceGenerated::dispatch(
            $invoice,
            $subscription->billable,
            $invoice->number,
            $invoice->total,
            $invoice->currency,
            $invoice->due_date,
        );

        return $invoice;
    }

    /**
     * Generate a credit note referencing an original invoice.
     */
    public function generateCreditNote(Invoice $originalInvoice, int $amount, string $reason): Invoice
    {
        $creditNote = new Invoice([
            'ulid' => Str::ulid()->toBase32(),
            'number' => $this->generateInvoiceNumber(),
            'subscription_id' => $originalInvoice->subscription_id,
            'status' => InvoiceStatus::PAID,
            'currency' => $originalInvoice->currency,
            'subtotal' => -$amount,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total' => -$amount,
            'due_date' => now(),
            'paid_at' => now(),
            'metadata' => [
                'is_credit_note' => true,
                'original_invoice_id' => $originalInvoice->id,
                'reason' => $reason,
            ],
            'notes' => "Credit note: {$reason}",
        ]);

        $originalInvoice->billable->morphMany(Invoice::class, 'billable')->save($creditNote);

        $creditNote->items()->create([
            'description' => "Credit: {$reason}",
            'quantity' => 1,
            'unit_price' => -$amount,
            'total' => -$amount,
        ]);

        return $creditNote->load('items');
    }

    /**
     * Find and mark overdue invoices. Returns the count of invoices marked overdue.
     */
    public function markOverdue(): int
    {
        return Invoice::query()
            ->whereNotIn('status', [
                InvoiceStatus::PAID->value,
                InvoiceStatus::VOID->value,
                InvoiceStatus::OVERDUE->value,
            ])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->startOfDay())
            ->update(['status' => InvoiceStatus::OVERDUE->value]);
    }

    /**
     * Resolve the invoice due-day window for a billable (runtime-overridable).
     */
    protected function dueDays(?Model $billable): int
    {
        $raw = billing_setting('invoices.due_days', 30, $billable);

        return is_numeric($raw) ? (int) $raw : 30;
    }

    /**
     * Generate a sequential invoice number.
     *
     * The prefix is runtime-overridable, but resolved globally: the sequence it
     * keys (one running counter per prefix+year) is system-wide, not per-tenant.
     */
    protected function generateInvoiceNumber(): string
    {
        $prefixRaw = billing_setting('invoices.prefix', 'INV');
        $prefix = is_string($prefixRaw) ? $prefixRaw : 'INV';
        $year = now()->year;
        $paddingRaw = config('billing.invoices.sequence_padding', 4);
        $padding = is_numeric($paddingRaw) ? (int) $paddingRaw : 4;

        $lastInvoice = Invoice::query()->where('number', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('number')
            ->first();

        $sequence = 1;

        if ($lastInvoice !== null) {
            $parts = explode('-', $lastInvoice->number);
            $sequence = ((int) end($parts)) + 1;
        }

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT));
    }
}
