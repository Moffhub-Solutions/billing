<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Moffhub\Billing\Http\Resources\PaymentProofResource;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\PaymentProof;
use Moffhub\Billing\Services\PaymentProofService;

/**
 * Back-office management of off-platform proofs of payment. The whole group is
 * admin-gated (see routes); submitting a proof records it as pending, and a
 * separate verify step settles it into a completed Payment.
 */
class PaymentProofController extends BillingController
{
    public function __construct(
        protected PaymentProofService $proofs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PaymentProof::query()->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $proofs = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => PaymentProofResource::collection($proofs->items()),
            'meta' => [
                'current_page' => $proofs->currentPage(),
                'last_page' => $proofs->lastPage(),
                'per_page' => $proofs->perPage(),
                'total' => $proofs->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'channel' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payer_detail' => ['sometimes', 'nullable', 'string', 'max:255'],
            'proof_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $invoice = Invoice::query()->find($validated['invoice_id']);

        if (! $invoice instanceof Invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $billable = $invoice->billable;
        $currencyInput = $validated['currency'] ?? null;

        $proof = $this->proofs->submit($billable, [
            ...$validated,
            'subscription_id' => $invoice->subscription_id,
            'currency' => is_string($currencyInput) ? $currencyInput : $invoice->currency,
            'submitted_by' => $this->actorId($request),
        ]);

        return response()->json([
            'message' => 'Proof of payment recorded; awaiting verification.',
            'data' => new PaymentProofResource($proof),
        ], 201);
    }

    public function verify(Request $request, int $proof): JsonResponse
    {
        $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        $model = PaymentProof::query()->findOrFail($proof);
        $notes = $request->input('notes');

        try {
            $verified = $this->proofs->verify($model, $this->actorId($request), is_string($notes) ? $notes : null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Proof of payment verified.',
            'data' => new PaymentProofResource($verified->fresh() ?? $verified),
        ]);
    }

    public function reject(Request $request, int $proof): JsonResponse
    {
        $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        $model = PaymentProof::query()->findOrFail($proof);
        $reason = $request->input('reason');

        try {
            $rejected = $this->proofs->reject($model, $this->actorId($request), is_string($reason) ? $reason : null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Proof of payment rejected.',
            'data' => new PaymentProofResource($rejected->fresh() ?? $rejected),
        ]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return (is_string($id) || is_int($id)) ? (string) $id : null;
    }
}
