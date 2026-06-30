<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Moffhub\Billing\Contracts\BillableInterface;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Events\PaymentFailed;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;

/**
 * Applies a confirmed webhook/re-query outcome to the matching Payment row.
 *
 * Shared by the webhook controller (verified callbacks, applied inline) and the
 * async re-query job (unverified callbacks, applied after the provider's status
 * API confirms the result), so both paths use the same locked, idempotent
 * settlement and event dispatch.
 */
class WebhookSettlement
{
    /**
     * Whether a still-pending payment exists for this provider payment id.
     * Used to avoid enqueuing re-query jobs for unknown/forged identifiers.
     */
    public function hasPendingPayment(string $providerPaymentId): bool
    {
        if ($providerPaymentId === '') {
            return false;
        }

        return Payment::query()
            ->where('provider_payment_id', $providerPaymentId)
            ->where('status', PaymentStatus::PENDING->value)
            ->exists();
    }

    /**
     * Settle a payment from a normalized provider status string. No-op for
     * non-terminal states, unknown ids, or rows already in the target state.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function settle(string $providerName, ?string $providerPaymentId, string $statusString, array $metadata = []): void
    {
        if ($providerPaymentId === null || $providerPaymentId === '') {
            Log::warning("Billing webhook: Missing provider_payment_id from {$providerName}");

            return;
        }

        $newStatus = $this->mapStatus($statusString);

        if ($newStatus === null) {
            return;
        }

        $completedPaymentId = null;

        DB::transaction(function () use ($providerName, $providerPaymentId, $newStatus, $metadata, &$completedPaymentId): void {
            $payment = Payment::query()
                ->where('provider_payment_id', $providerPaymentId)
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
            $providerRef = $metadata['provider_reference'] ?? $payment->provider_reference;
            $payment->provider_reference = is_string($providerRef) ? $providerRef : $payment->provider_reference;

            $payment->metadata = array_merge((array) ($payment->metadata ?? []), $metadata);

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
                $completedPaymentId = $payment->id;

                PaymentReceived::dispatch(
                    $payment,
                    $billable,
                    (int) $payment->amount,
                    (string) $payment->currency,
                    $payment->payment_method?->value,
                    $payment->provider_reference,
                );
            } elseif ($newStatus === PaymentStatus::FAILED) {
                $failureReasonRaw = $metadata['failure_reason'] ?? 'Webhook reported failure';
                $failureReason = is_string($failureReasonRaw) ? $failureReasonRaw : 'Webhook reported failure';

                PaymentFailed::dispatch(
                    $payment,
                    $billable,
                    (int) $payment->amount,
                    (string) $payment->currency,
                    $failureReason,
                );
            }
        });

        if ($completedPaymentId !== null) {
            $this->onPaymentCompleted($completedPaymentId);
        }
    }

    /**
     * Post-commit settlement for a completed payment: recalculate the linked
     * invoice's status and advance the next tranche of a split payment.
     */
    protected function onPaymentCompleted(int $paymentId): void
    {
        $payment = Payment::query()->find($paymentId);

        if ($payment === null) {
            return;
        }

        if ($payment->invoice_id !== null) {
            DB::transaction(function () use ($payment): void {
                $invoice = Invoice::query()->lockForUpdate()->find($payment->invoice_id);
                $invoice?->recalculateStatus();
            });
        }

        if (! $payment->isSplit()) {
            return;
        }

        $payment->loadMissing('billable');
        $billable = $payment->billable;

        if ($billable instanceof Model && $billable instanceof BillableInterface) {
            app(SplitPaymentService::class)->advance($payment, $billable);
        }
    }

    /**
     * Normalize a provider's status string to a PaymentStatus. Returns null for
     * non-terminal states (pending, processing).
     */
    public function mapStatus(string $status): ?PaymentStatus
    {
        return match (strtolower($status)) {
            'completed', 'success', 'successful', 'paid', 'confirmed' => PaymentStatus::COMPLETED,
            'failed', 'failure', 'cancelled', 'canceled', 'declined', 'expired' => PaymentStatus::FAILED,
            'refunded', 'reversed' => PaymentStatus::REFUNDED,
            default => null,
        };
    }
}
