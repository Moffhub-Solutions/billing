<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Http\Requests\StorePaymentRequest;
use Moffhub\Billing\Http\Resources\PaymentResource;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\PaymentManager;

class PaymentController extends BillingController
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

        $defaultProvider = config('billing.default_provider', 'manual');
        $providerInput = $request->input('provider', is_string($defaultProvider) ? $defaultProvider : 'manual');
        $provider = is_string($providerInput) ? $providerInput : 'manual';
        $driver = $this->paymentManager->driver($provider);

        if (! $driver instanceof PaymentProviderInterface) {
            return response()->json(['message' => 'Invalid payment provider.'], 500);
        }

        $currencyDefault = config('billing.currency', 'KES');
        $currency = $request->string('currency', is_string($currencyDefault) ? $currencyDefault : 'KES')->toString();

        $optionsRaw = $request->input('options', []);
        $options = is_array($optionsRaw) ? $optionsRaw : [];

        // Initiate charge via provider
        $result = $driver->charge(
            $request->integer('amount'),
            $currency,
            $options,
        );

        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'subscription_id' => $request->input('subscription_id'),
            'invoice_id' => $request->input('invoice_id'),
            'amount' => $request->integer('amount'),
            'currency' => $currency,
            'status' => $result['success'] ? $result['status'] : 'failed',
            'payment_provider' => $provider,
            'provider_payment_id' => $result['provider_payment_id'],
            'provider_reference' => $result['provider_reference'],
            'payment_method' => $request->input('payment_method'),
            'metadata' => $result['metadata'],
            'paid_at' => $result['status'] === 'completed' ? now() : null,
            'failed_at' => $result['success'] ? null : now(),
        ]);

        $billable->payments()->save($payment);

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
        $paymentModel = Payment::query()->findOrFail($payment);

        return response()->json([
            'data' => new PaymentResource($paymentModel),
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

        $paymentModel = Payment::query()->findOrFail($payment);

        if (! $paymentModel->isCompleted()) {
            return response()->json([
                'message' => 'Only completed payments can be refunded.',
            ], 422);
        }

        $providerPaymentId = $paymentModel->provider_payment_id;

        if ($providerPaymentId === null) {
            return response()->json([
                'message' => 'Payment has no provider_payment_id; cannot refund.',
            ], 422);
        }

        $defaultProvider = config('billing.default_provider', 'manual');
        $provider = $paymentModel->payment_provider ?? (is_string($defaultProvider) ? $defaultProvider : 'manual');
        $driver = $this->paymentManager->driver($provider);

        if (! $driver instanceof PaymentProviderInterface) {
            return response()->json(['message' => 'Invalid payment provider.'], 500);
        }

        $amountRaw = $request->input('amount');

        $result = $driver->refund(
            $providerPaymentId,
            is_numeric($amountRaw) ? (int) $amountRaw : null,
            ['reason' => $request->input('reason')],
        );

        if ($result['success']) {
            $paymentModel->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'metadata' => array_merge($paymentModel->metadata ?? [], [
                    'refund' => $result,
                ]),
            ]);
        }

        $fresh = $paymentModel->fresh();

        return response()->json([
            'message' => $result['success'] ? 'Payment refunded.' : 'Refund failed.',
            'data' => new PaymentResource($fresh ?? $paymentModel),
        ], $result['success'] ? 200 : 422);
    }
}
