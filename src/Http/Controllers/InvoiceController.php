<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Http\Requests\StoreInvoiceRequest;
use Moffhub\Billing\Http\Resources\InvoiceResource;
use Moffhub\Billing\Models\Invoice;

class InvoiceController extends Controller
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

        $invoice = new Invoice([
            'ulid' => Str::ulid()->toBase32(),
            'number' => $this->generateInvoiceNumber(),
            'subscription_id' => $request->input('subscription_id'),
            'status' => InvoiceStatus::DRAFT,
            'currency' => $request->input('currency', config('billing.currency', 'KES')),
            'tax_rate' => $request->input('tax_rate', config('billing.tax.default_rate', 16.0)),
            'due_date' => $request->input('due_date', now()->addDays(config('billing.invoices.due_days', 30))),
            'notes' => $request->input('notes'),
            'metadata' => $request->input('metadata'),
        ]);

        $billable->morphMany(Invoice::class, 'billable')->save($invoice);

        // Add line items
        $subtotal = 0;

        foreach ($request->input('items', []) as $item) {
            $itemTotal = ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0);
            $subtotal += $itemTotal;

            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => $item['unit_price'] ?? 0,
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

        return response()->json([
            'message' => 'Invoice created.',
            'data' => new InvoiceResource($invoice->fresh()->load('items')),
        ], 201);
    }

    /**
     * Show a single invoice with line items.
     */
    public function show(int $invoice): JsonResponse
    {
        $invoice = Invoice::with('items', 'payments')->findOrFail($invoice);

        return response()->json([
            'data' => new InvoiceResource($invoice),
        ]);
    }

    /**
     * Mark invoice as sent.
     */
    public function send(int $invoice): JsonResponse
    {
        $invoice = Invoice::findOrFail($invoice);

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
    public function void(int $invoice): JsonResponse
    {
        $invoice = Invoice::findOrFail($invoice);

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

        $invoice = Invoice::findOrFail($invoice);

        if ($invoice->isPaid()) {
            return response()->json(['message' => 'Invoice is already paid.'], 422);
        }

        $invoice->update([
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'metadata' => array_merge($invoice->metadata ?? [], [
                'manual_payment' => [
                    'reference' => $request->input('payment_reference'),
                    'notes' => $request->input('notes'),
                    'marked_by' => $request->user()?->getAuthIdentifier(),
                    'marked_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        // Create a corresponding payment record
        $payment = $invoice->billable->payments()->create([
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
            $invoice->billable,
            $payment->amount,
            $payment->currency,
            $payment->payment_method?->value,
            $payment->provider_reference,
        );

        return response()->json([
            'message' => 'Invoice marked as paid.',
            'data' => new InvoiceResource($invoice->fresh()->load('items')),
        ]);
    }

    /**
     * Generate a sequential invoice number.
     */
    protected function generateInvoiceNumber(): string
    {
        $prefix = config('billing.invoices.prefix', 'INV');
        $year = now()->year;
        $padding = config('billing.invoices.sequence_padding', 4);

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

    protected function resolveBillable(Request $request): mixed
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (method_exists($user, 'subscriptions')) {
            return $user;
        }

        $billableRelation = config('billing.billable_relation', 'company');

        if (method_exists($user, $billableRelation)) {
            return $user->{$billableRelation};
        }

        return null;
    }
}
