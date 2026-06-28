<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Exceptions\TransactionLimitException;
use Moffhub\Billing\Http\Requests\StorePaymentRequest;
use Moffhub\Billing\Http\Resources\PaymentResource;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\SplitPaymentService;

class PaymentController extends BillingController
{
    public function __construct(
        protected PaymentManager $paymentManager,
        protected SplitPaymentService $splitPayments,
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
     * List the payment options offered to customers at checkout.
     *
     * The frontend renders its payment selector from this list; the chosen
     * `provider` is then passed back to `store()`.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => $this->paymentManager->getPaymentOptions(),
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

        // Only providers offered at checkout may be charged. The default
        // provider is always permitted so server-initiated charges still work.
        $enabled = $this->paymentManager->getEnabledProviders();
        if ($enabled !== [] && $provider !== $defaultProvider && ! in_array($provider, $enabled, true)) {
            return response()->json([
                'message' => "Payment provider '{$provider}' is not enabled.",
                'enabled_providers' => $enabled,
            ], 422);
        }

        if (! $this->paymentManager->driver($provider) instanceof PaymentProviderInterface) {
            return response()->json(['message' => 'Invalid payment provider.'], 500);
        }

        $currencyDefault = config('billing.currency', 'KES');
        $currency = $request->string('currency', is_string($currencyDefault) ? $currencyDefault : 'KES')->toString();

        $optionsRaw = $request->input('options', []);
        $options = is_array($optionsRaw) ? $optionsRaw : [];

        // Initiate the payment. Amounts above the provider's per-transaction
        // limit are split into a group of tranche payments automatically.
        try {
            $result = $this->splitPayments->process(
                billable: $billable,
                provider: $provider,
                amount: $request->integer('amount'),
                currency: $currency,
                options: $options,
                attributes: [
                    'subscription_id' => $request->input('subscription_id'),
                    'invoice_id' => $request->input('invoice_id'),
                    'payment_method' => $request->input('payment_method'),
                ],
            );
        } catch (TransactionLimitException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'provider' => $e->provider,
                'tranches_needed' => $e->tranchesNeeded,
                'tranches_allowed' => $e->tranchesAllowed,
            ], 422);
        }

        $payments = $result['payments'];
        $first = $payments[0];

        // Single payment: keep the original response shape for compatibility.
        if (! $result['split']) {
            return response()->json([
                'message' => $result['success'] ? 'Payment initiated.' : 'Payment failed.',
                'data' => new PaymentResource($first),
            ], $result['success'] ? 201 : 422);
        }

        // Split payment: return the whole tranche group.
        return response()->json([
            'message' => 'Split payment initiated.',
            'data' => PaymentResource::collection($payments),
            'meta' => [
                'split' => true,
                'group' => $result['group'],
                'tranche_count' => $result['tranche_count'],
                'total_amount' => array_sum(array_map(fn (Payment $p): int => $p->amount, $payments)),
            ],
        ], $result['success'] ? 201 : 422);
    }

    /**
     * Initiate a specific pending tranche of a split payment.
     *
     * For app-driven (non auto-advance) collection: the client walks the group
     * and collects each tranche when the payer is ready.
     */
    public function collect(Request $request, int $payment): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $paymentModel = Payment::query()->findOrFail($payment);

        if (! $paymentModel->isPending()) {
            return response()->json(['message' => 'Only pending tranches can be collected.'], 422);
        }

        $optionsRaw = $request->input('options', []);
        $options = is_array($optionsRaw) ? $optionsRaw : [];

        $result = $this->splitPayments->collectTranche($paymentModel, $billable, $options);

        $fresh = $paymentModel->fresh();

        return response()->json([
            'message' => $result['success'] ? 'Tranche initiated.' : 'Tranche failed.',
            'data' => new PaymentResource($fresh ?? $paymentModel),
        ], $result['success'] ? 200 : 422);
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
