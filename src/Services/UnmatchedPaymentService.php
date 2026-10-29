<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\PaymentMethod;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\UnmatchedPaymentStatus;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\UnmatchedPayment;

/**
 * A person's decision about money the paybill received but could not place:
 * put it on the invoice it was meant for, or set it aside with a reason.
 */
class UnmatchedPaymentService
{
    public function __construct(private readonly SubscriptionRecovery $recovery) {}

    /**
     * Credit the payment to an invoice as a completed M-Pesa payment, settle the
     * invoice, restore its subscription if the invoice was what held it back,
     * and tell the app. An amount above what the invoice still owed is kept and
     * flagged, never silently absorbed.
     */
    public function allocate(UnmatchedPayment $unmatched, Invoice $invoice, ?string $by = null, ?string $notes = null): UnmatchedPayment
    {
        return DB::transaction(function () use ($unmatched, $invoice, $by, $notes): UnmatchedPayment {
            $locked = UnmatchedPayment::query()->lockForUpdate()->find($unmatched->id);

            if (! $locked instanceof UnmatchedPayment || ! $locked->isOpen()) {
                throw new InvalidArgumentException('Only a payment that still needs allocating can be allocated.');
            }

            $target = Invoice::query()->lockForUpdate()->find($invoice->id);

            if (! $target instanceof Invoice
                || ! in_array($target->status, [InvoiceStatus::SENT, InvoiceStatus::OVERDUE, InvoiceStatus::PARTIALLY_PAID], true)
                || $target->total <= 0) {
                throw new InvalidArgumentException('That invoice is not open for payment.');
            }

            if ($target->currency !== $locked->currency) {
                throw new InvalidArgumentException('The payment is in '.$locked->currency.' but the invoice is in '.$target->currency.'.');
            }

            $billable = $target->billable;
            $owed = max(0, $target->total - $target->amountPaid());

            $payment = new Payment([
                'ulid' => Str::ulid()->toBase32(),
                'subscription_id' => $target->subscription_id,
                'invoice_id' => $target->id,
                'amount' => $locked->amount,
                'currency' => $locked->currency,
                'status' => PaymentStatus::COMPLETED,
                'payment_provider' => $locked->provider,
                'payment_method' => PaymentMethod::MPESA,
                'provider_payment_id' => $locked->provider_payment_id,
                'paid_at' => $locked->paid_at ?? now(),
                'metadata' => array_filter([
                    'source' => 'paybill_allocated',
                    'bill_ref_number' => $locked->account_reference,
                    'phone' => $locked->payer_phone,
                    'allocated_by' => $by,
                    'overpaid_by' => $locked->amount > $owed ? $locked->amount - $owed : null,
                ], static fn (mixed $value): bool => $value !== null),
            ]);
            $billable->morphMany(Payment::class, 'billable')->save($payment);

            $target->recalculateStatus();
            $this->recovery->afterInvoicePaid($target->refresh());

            $locked->status = UnmatchedPaymentStatus::ALLOCATED;
            $locked->payment_id = $payment->id;
            $locked->resolved_by = $by;
            $locked->resolved_at = now();
            $locked->resolution_notes = $notes;
            $locked->save();

            PaymentReceived::dispatch(
                $payment,
                $billable,
                (int) $payment->amount,
                (string) $payment->currency,
                PaymentMethod::MPESA->value,
                $payment->provider_reference,
            );

            return $locked;
        });
    }

    /**
     * Set the payment aside (for example money meant for someone else, to be
     * refunded outside billing). The reason is required so the decision can be
     * explained later.
     */
    public function dismiss(UnmatchedPayment $unmatched, string $reason, ?string $by = null): UnmatchedPayment
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to dismiss a payment.');
        }

        return DB::transaction(function () use ($unmatched, $reason, $by): UnmatchedPayment {
            $locked = UnmatchedPayment::query()->lockForUpdate()->find($unmatched->id);

            if (! $locked instanceof UnmatchedPayment || ! $locked->isOpen()) {
                throw new InvalidArgumentException('Only a payment that still needs allocating can be dismissed.');
            }

            $locked->status = UnmatchedPaymentStatus::DISMISSED;
            $locked->resolved_by = $by;
            $locked->resolved_at = now();
            $locked->resolution_notes = $reason;
            $locked->save();

            return $locked;
        });
    }
}
