<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Moffhub\Billing\Http\Resources\UnmatchedPaymentResource;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\UnmatchedPayment;
use Moffhub\Billing\Services\UnmatchedPaymentService;

/**
 * Back-office list of paybill payments that named no invoice we could use. The
 * whole group is admin-gated (see routes).
 */
class UnmatchedPaymentController extends BillingController
{
    public function __construct(
        protected UnmatchedPaymentService $unmatched,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = UnmatchedPayment::query()->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $payments = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => UnmatchedPaymentResource::collection($payments->items()),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function allocate(Request $request, int $payment): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $model = UnmatchedPayment::query()->findOrFail($payment);
        $invoice = Invoice::query()->find($validated['invoice_id']);

        if (! $invoice instanceof Invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $notes = $validated['notes'] ?? null;

        try {
            $allocated = $this->unmatched->allocate($model, $invoice, $this->actorId($request), is_string($notes) ? $notes : null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Payment allocated to the invoice.',
            'data' => new UnmatchedPaymentResource($allocated->fresh() ?? $allocated),
        ]);
    }

    public function dismiss(Request $request, int $payment): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $model = UnmatchedPayment::query()->findOrFail($payment);

        try {
            $dismissed = $this->unmatched->dismiss($model, (string) $validated['reason'], $this->actorId($request));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Payment dismissed.',
            'data' => new UnmatchedPaymentResource($dismissed->fresh() ?? $dismissed),
        ]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return (is_string($id) || is_int($id)) ? (string) $id : null;
    }
}
