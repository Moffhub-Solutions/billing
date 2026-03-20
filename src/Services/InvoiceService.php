<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\TaxCalculatorInterface;
use Moffhub\Billing\Enums\InvoiceStatus;
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
        $currency = $plan->currency ?? config('billing.currency', 'KES');

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
            'due_date' => now()->addDays((int) config('billing.invoices.due_days', 30)),
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

        return $invoice->load('items');
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
     * Generate a sequential invoice number.
     */
    protected function generateInvoiceNumber(): string
    {
        $prefix = config('billing.invoices.prefix', 'INV');
        $year = now()->year;
        $padding = (int) config('billing.invoices.sequence_padding', 4);

        $lastInvoice = Invoice::where('number', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('number')
            ->first();

        $sequence = 1;

        if ($lastInvoice !== null) {
            $parts = explode('-', (string) $lastInvoice->number);
            $sequence = ((int) end($parts)) + 1;
        }

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT));
    }
}
