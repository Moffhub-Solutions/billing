<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\BillableInterface;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Http\Requests\StoreInvoiceRequest;
use Moffhub\Billing\Http\Resources\InvoiceResource;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;

class InvoiceController extends BillingController
{
    /**
     * List invoices for the authenticated billable.
     */
    public function index(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $invoices = Invoice::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('items')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => InvoiceResource::collection($invoices->items()),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /**
     * Create a manual invoice.
     */
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $currencyDefault = billing_setting('currency', 'KES', $billable);
        $currency = $request->input('currency', is_string($currencyDefault) ? $currencyDefault : 'KES');

        $taxRateDefault = billing_setting('tax.default_rate', 16.0, $billable);
        $taxRateInput = $request->input('tax_rate', $taxRateDefault);
        $taxRate = is_numeric($taxRateInput) ? (float) $taxRateInput : 16.0;

        $dueDaysRaw = billing_setting('invoices.due_days', 30, $billable);
        $dueDays = is_numeric($dueDaysRaw) ? (int) $dueDaysRaw : 30;
        $dueDate = $request->input('due_date', now()->addDays($dueDays));

        $invoice = new Invoice([
            'ulid' => Str::ulid()->toBase32(),
            'number' => $this->generateInvoiceNumber(),
            'subscription_id' => $request->input('subscription_id'),
            'status' => InvoiceStatus::DRAFT,
            'currency' => $currency,
            'tax_rate' => $taxRate,
            'due_date' => $dueDate,
            'notes' => $request->input('notes'),
            'metadata' => $request->input('metadata'),
        ]);

        $billable->morphMany(Invoice::class, 'billable')->save($invoice);

        // Add line items
        $subtotal = 0;

        $itemsRaw = $request->input('items', []);
        $items = is_array($itemsRaw) ? $itemsRaw : [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantityRaw = $item['quantity'] ?? 1;
            $quantity = is_numeric($quantityRaw) ? (int) $quantityRaw : 1;

            $unitPriceRaw = $item['unit_price'] ?? 0;
            $unitPrice = is_numeric($unitPriceRaw) ? (int) $unitPriceRaw : 0;

            $itemTotal = $quantity * $unitPrice;
            $subtotal += $itemTotal;

            $description = $item['description'] ?? '';

            $invoice->items()->create([
                'description' => is_string($description) ? $description : '',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $itemTotal,
                'feature_slug' => $item['feature_slug'] ?? null,
                'period_start' => $item['period_start'] ?? null,
                'period_end' => $item['period_end'] ?? null,
            ]);
        }

        // Calculate totals
        $taxAmount = $invoice->tax_rate > 0
            ? (int) round($subtotal * ($invoice->tax_rate / 100))
            : 0;

        $invoice->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $subtotal + $taxAmount,
        ]);

        $fresh = $invoice->fresh();

        return response()->json([
            'message' => 'Invoice created.',
            'data' => new InvoiceResource($fresh !== null ? $fresh->load('items') : $invoice),
        ], 201);
    }

    /**
     * Show a single invoice with line items.
     */
    public function show(Request $request, int $invoice): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $invoice = $this->ownedInvoices($billable)->with('items', 'payments')->findOrFail($invoice);

        return response()->json([
            'data' => new InvoiceResource($invoice),
        ]);
    }

    /**
     * Mark invoice as sent.
     */
    public function send(Request $request, int $invoice): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $invoice = $this->ownedInvoices($billable)->findOrFail($invoice);

        if ($invoice->status !== InvoiceStatus::DRAFT) {
            return response()->json(['message' => 'Only draft invoices can be sent.'], 422);
        }

        $invoice->update(['status' => InvoiceStatus::SENT]);

        return response()->json([
            'message' => 'Invoice marked as sent.',
            'data' => new InvoiceResource($invoice->fresh()),
        ]);
    }

    /**
     * Void an invoice.
     */
    public function void(Request $request, int $invoice): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $invoice = $this->ownedInvoices($billable)->findOrFail($invoice);

        if ($invoice->status === InvoiceStatus::PAID) {
            return response()->json(['message' => 'Cannot void a paid invoice. Issue a credit note instead.'], 422);
        }

        $invoice->update(['status' => InvoiceStatus::VOID]);

        return response()->json([
            'message' => 'Invoice voided.',
            'data' => new InvoiceResource($invoice->fresh()),
        ]);
    }

    /**
     * Mark an invoice as paid (for manual/offline payments).
     */
    public function markPaid(Request $request, int $invoice): JsonResponse
    {
        $request->validate([
            'payment_reference' => ['sometimes', 'string', 'max:255'],
            'notes' => ['sometimes', 'string', 'max:1000'],
        ]);

        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $invoice = $this->ownedInvoices($billable)->findOrFail($invoice);

        if ($invoice->isPaid()) {
            return response()->json(['message' => 'Invoice is already paid.'], 422);
        }

        $user = $request->user();
        $markedBy = $user?->getAuthIdentifier();

        $invoice->update([
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'metadata' => array_merge($invoice->metadata ?? [], [
                'manual_payment' => [
                    'reference' => $request->input('payment_reference'),
                    'notes' => $request->input('notes'),
                    'marked_by' => $markedBy,
                    'marked_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        $billable = $invoice->billable;

        // Create a corresponding payment record
        $payment = $billable->morphMany(Payment::class, 'billable')->create([
            'ulid' => Str::ulid()->toBase32(),
            'subscription_id' => $invoice->subscription_id,
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'currency' => $invoice->currency,
            'status' => 'completed',
            'payment_provider' => 'manual',
            'provider_reference' => $request->input('payment_reference'),
            'payment_method' => 'manual',
            'paid_at' => now(),
        ]);

        PaymentReceived::dispatch(
            $payment,
            $billable,
            $payment->amount,
            $payment->currency,
            $payment->payment_method?->value,
            $payment->provider_reference,
        );

        $fresh = $invoice->fresh();

        return response()->json([
            'message' => 'Invoice marked as paid.',
            'data' => new InvoiceResource($fresh !== null ? $fresh->load('items') : $invoice),
        ]);
    }

    /**
     * @param  Model&BillableInterface  $billable
     * @return Builder<Invoice>
     */
    private function ownedInvoices(Model $billable): Builder
    {
        return Invoice::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey());
    }

    /**
     * Generate a sequential invoice number.
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
