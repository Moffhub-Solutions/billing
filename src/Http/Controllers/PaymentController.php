<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Http\Requests\StorePaymentRequest;
use Moffhub\Billing\Http\Resources\PaymentResource;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\PaymentManager;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentManager $paymentManager,
    ) {}

    /**
     * List payments for the authenticated billable.
     */
    public function index(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $payments = $billable->payments()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('provider'), fn ($q) => $q->where('payment_provider', $request->input('provider')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => PaymentResource::collection($payments->items()),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * Initiate a payment.
     */
    public function store(StorePaymentRequest $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $provider = $request->input('provider', config('billing.default_provider'));
        $driver = $this->paymentManager->driver($provider);

        // Initiate charge via provider
        $result = $driver->charge(
            $request->integer('amount'),
            $request->input('currency', config('billing.currency', 'KES')),
            $request->input('options', []),
        );

        // Record the payment
        $payment = $billable->payments()->create([
            'ulid' => Str::ulid()->toBase32(),
            'subscription_id' => $request->input('subscription_id'),
            'invoice_id' => $request->input('invoice_id'),
            'amount' => $request->integer('amount'),
            'currency' => $request->input('currency', config('billing.currency', 'KES')),
            'status' => $result['success'] ? ($result['status'] ?? 'pending') : 'failed',
            'payment_provider' => $provider,
            'provider_payment_id' => $result['provider_payment_id'] ?? null,
            'provider_reference' => $result['provider_reference'] ?? null,
            'payment_method' => $request->input('payment_method'),
            'metadata' => $result['metadata'] ?? null,
            'paid_at' => $result['status'] === 'completed' ? now() : null,
            'failed_at' => $result['success'] ? null : now(),
        ]);

        if ($payment->isCompleted()) {
            PaymentReceived::dispatch(
                $payment,
                $billable,
                $payment->amount,
                $payment->currency,
                $payment->payment_method?->value,
                $payment->provider_reference,
            );
        }

        return response()->json([
            'message' => $result['success'] ? 'Payment initiated.' : 'Payment failed.',
            'data' => new PaymentResource($payment),
        ], $result['success'] ? 201 : 422);
    }

    /**
     * Show a single payment.
     */
    public function show(int $payment): JsonResponse
    {
        $payment = Payment::findOrFail($payment);

        return response()->json([
            'data' => new PaymentResource($payment),
        ]);
    }

    /**
     * Refund a payment.
     */
    public function refund(Request $request, int $payment): JsonResponse
    {
        $request->validate([
            'amount' => ['sometimes', 'integer', 'min:1'],
            'reason' => ['sometimes', 'string', 'max:500'],
        ]);

        $payment = Payment::findOrFail($payment);

        if (! $payment->isCompleted()) {
            return response()->json([
                'message' => 'Only completed payments can be refunded.',
            ], 422);
        }

        $driver = $this->paymentManager->driver($payment->payment_provider ?? config('billing.default_provider'));

        $result = $driver->refund(
            $payment->provider_payment_id,
            $request->input('amount'),
            ['reason' => $request->input('reason')],
        );

        if ($result['success']) {
            $payment->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'metadata' => array_merge($payment->metadata ?? [], [
                    'refund' => $result,
                ]),
            ]);
        }

        return response()->json([
            'message' => $result['success'] ? 'Payment refunded.' : 'Refund failed.',
            'data' => new PaymentResource($payment->fresh()),
        ], $result['success'] ? 200 : 422);
    }

    protected function resolveBillable(Request $request): mixed
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (method_exists($user, 'payments')) {
            return $user;
        }

        $billableRelation = config('billing.billable_relation', 'company');

        if (method_exists($user, $billableRelation)) {
            return $user->{$billableRelation};
        }

        return null;
    }
}
