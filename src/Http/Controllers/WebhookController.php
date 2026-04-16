<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\PaymentManager;

class WebhookController extends Controller
{
    public function __construct(
        protected PaymentManager $paymentManager,
    ) {}

    /**
     * Handle M-Pesa callback.
     */
    public function mpesa(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'mpesa');
    }

    /**
     * Handle Paystack webhook.
     */
    public function paystack(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'paystack');
    }

    /**
     * Handle Flutterwave webhook.
     */
    public function flutterwave(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'flutterwave');
    }

    /**
     * Handle Pesapal IPN.
     */
    public function pesapal(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'pesapal');
    }

    /**
     * Handle Airtel Money callback.
     */
    public function airtel(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'airtel');
    }

    /**
     * Handle KCB BUNI IPN.
     */
    public function kcb(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'kcb');
    }

    /**
     * Handle Equity Jenga webhook.
     */
    public function jenga(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'jenga');
    }

    /**
     * Handle Co-operative Bank callback.
     */
    public function coopbank(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'coopbank');
    }

    /**
     * Handle Stanbic Bank webhook.
     */
    public function stanbic(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'stanbic');
    }

    /**
     * Handle NCBA IPN.
     */
    public function ncba(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'ncba');
    }

    /**
     * Handle IntaSend webhook.
     */
    public function intasend(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'intasend');
    }

    /**
     * Process a webhook from any provider.
     */
    protected function handleWebhook(Request $request, string $providerName): JsonResponse
    {
        try {
            $driver = $this->paymentManager->driver($providerName);

            // Verify signature
            if (! $driver->verifyWebhook($request)) {
                Log::warning("Billing webhook: Invalid signature from {$providerName}", [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['error' => 'Invalid signature'], 403);
            }

            // Parse the webhook payload
            $event = $driver->parseWebhook($request);

            Log::info("Billing webhook received: {$providerName}/{$event['event']}", [
                'provider' => $providerName,
                'event' => $event['event'],
                'provider_payment_id' => $event['provider_payment_id'],
            ]);

            $this->processEvent($providerName, $event);

            return response()->json(['status' => 'received']);
        } catch (\Throwable $e) {
            Log::error("Billing webhook error: {$providerName}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Apply a parsed webhook event to the matching `Payment` row and fire the
     * corresponding domain event.
     *
     * Webhooks can be retried by the provider (M-Pesa, Pesapal, KCB all do
     * this), so the update has to be idempotent: a row already in a terminal
     * state matching the inbound status is left untouched and no event is
     * dispatched. The lookup + update is wrapped in a transaction with a row
     * lock to prevent two simultaneous deliveries from racing.
     *
     * @param  array{event: string, provider_payment_id: string|null, status: string, amount: int|null, currency: string|null, metadata: array<string, mixed>}  $event
     */
    protected function processEvent(string $providerName, array $event): void
    {
        $providerPaymentId = $event['provider_payment_id'];

        if ($providerPaymentId === null || $providerPaymentId === '') {
            Log::warning("Billing webhook: Missing provider_payment_id from {$providerName}", [
                'event' => $event['event'],
            ]);

            return;
        }

        $newStatus = $this->mapStatus($event['status']);

        if ($newStatus === null) {
            Log::info("Billing webhook: Ignoring non-terminal status from {$providerName}", [
                'provider_payment_id' => $providerPaymentId,
                'status' => $event['status'],
            ]);

            return;
        }

        DB::transaction(function () use ($providerName, $providerPaymentId, $newStatus, $event): void {
            $payment = Payment::where('provider_payment_id', $providerPaymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                Log::warning("Billing webhook: No payment found for provider_payment_id from {$providerName}", [
                    'provider_payment_id' => $providerPaymentId,
                ]);

                return;
            }

            // Idempotency: terminal-state payments aren't reprocessed.
            if ($payment->status === $newStatus && $payment->status !== PaymentStatus::PENDING) {
                return;
            }

            $payment->status = $newStatus;
            $payment->payment_provider = $payment->payment_provider ?? $providerName;
            $payment->provider_reference = $event['metadata']['provider_reference']
                ?? $payment->provider_reference;

            $merged = array_merge((array) ($payment->metadata ?? []), $event['metadata']);
            $payment->metadata = $merged;

            if ($newStatus === PaymentStatus::COMPLETED) {
                $payment->paid_at = now();
            } elseif ($newStatus === PaymentStatus::FAILED) {
                $payment->failed_at = now();
            } elseif ($newStatus === PaymentStatus::REFUNDED) {
                $payment->refunded_at = now();
            }

            $payment->save();

            $payment->loadMissing('billable');
            $billable = $payment->billable;

            if ($billable === null) {
                Log::warning("Billing webhook: Payment {$payment->id} has no billable; skipping event dispatch", [
                    'provider' => $providerName,
                ]);

                return;
            }

            if ($newStatus === PaymentStatus::COMPLETED) {
                PaymentReceived::dispatch(
                    $payment,
                    $billable,
                    (int) $payment->amount,
                    (string) $payment->currency,
                    $payment->payment_method?->value,
                    $payment->provider_reference,
                );
            } elseif ($newStatus === PaymentStatus::FAILED) {
                PaymentFailed::dispatch(
                    $payment,
                    $billable,
                    (int) $payment->amount,
                    (string) $payment->currency,
                    $event['metadata']['failure_reason'] ?? 'Webhook reported failure',
                );
            }
        });
    }

    /**
     * Normalize a provider's status string to a `PaymentStatus` enum.
     *
     * Returns null for non-terminal states (pending, processing) — the
     * webhook is acknowledged but no event fires until the next callback.
     */
    protected function mapStatus(string $status): ?PaymentStatus
    {
        return match (strtolower($status)) {
            'completed', 'success', 'successful', 'paid', 'confirmed' => PaymentStatus::COMPLETED,
            'failed', 'failure', 'cancelled', 'canceled', 'declined', 'expired' => PaymentStatus::FAILED,
            'refunded', 'reversed' => PaymentStatus::REFUNDED,
            default => null,
        };
    }
}
