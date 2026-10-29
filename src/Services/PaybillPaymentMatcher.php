<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\PaymentMethod;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Enums\UnmatchedPaymentStatus;
use Moffhub\Billing\Events\PaybillPaymentUnmatched;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\UnmatchedPayment;

/**
 * A customer who pays the paybill by hand, entering an invoice number as the
 * account, starts no payment of ours, so the confirmation matches nothing and
 * the money would sit unrecorded. This finds the invoice the account number
 * names and records the payment against it, so the normal settlement then
 * completes it, updates the invoice and tells the app.
 *
 * A payment that names no invoice we can use is never dropped silently: the
 * app is told (PaybillPaymentUnmatched) so a person can allocate it.
 */
class PaybillPaymentMatcher
{
    public const RECORDED = 'recorded';

    public const DUPLICATE = 'duplicate';

    public const UNMATCHED = 'unmatched';

    /**
     * @param  array<array-key, mixed>  $c2b  a Daraja C2B confirmation
     * @return self::RECORDED|self::DUPLICATE|self::UNMATCHED
     */
    public function record(array $c2b): string
    {
        $transId = is_string($c2b['TransID'] ?? null) ? trim($c2b['TransID']) : '';

        if ($transId === '') {
            return self::UNMATCHED;
        }

        // Safaricom redelivers a confirmation until it is answered.
        if (Payment::query()->where('provider_payment_id', $transId)->exists()) {
            return self::DUPLICATE;
        }

        $amount = is_numeric($c2b['TransAmount'] ?? null) ? (int) round(((float) $c2b['TransAmount']) * 100) : 0;
        $reference = is_string($c2b['BillRefNumber'] ?? null) ? trim($c2b['BillRefNumber']) : '';
        $invoice = $amount > 0 ? $this->invoiceFor($reference) : null;

        if ($invoice === null || $invoice->currency !== 'KES') {
            $this->unmatched($c2b, $transId, $amount, $reference);

            return self::UNMATCHED;
        }

        $balance = max(0, $invoice->total - $invoice->amountPaid());

        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'subscription_id' => $invoice->subscription_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => 'KES',
            // Pending until the settlement that follows completes it, so the
            // invoice update and the PaymentReceived event run once, in one place.
            'status' => PaymentStatus::PENDING,
            'payment_provider' => 'mpesa',
            'payment_method' => PaymentMethod::MPESA,
            'provider_payment_id' => $transId,
            'metadata' => array_filter([
                'source' => 'paybill_c2b',
                'bill_ref_number' => $reference,
                'phone' => $c2b['MSISDN'] ?? null,
                'trans_time' => $c2b['TransTime'] ?? null,
                // More than the invoice still owed: kept, and flagged for a person.
                'overpaid_by' => $amount > $balance ? $amount - $balance : null,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
        $invoice->billable->morphMany(Payment::class, 'billable')->save($payment);

        return self::RECORDED;
    }

    /**
     * The invoice the account number names. A customer types it by hand, so
     * case, spaces and dashes are ignored ("inv 2026 0007" is INV-2026-0007).
     * Void and draft invoices and credit notes are never matched.
     */
    protected function invoiceFor(string $reference): ?Invoice
    {
        if ($reference === '') {
            return null;
        }

        $usable = [InvoiceStatus::SENT, InvoiceStatus::OVERDUE, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID];

        $exact = Invoice::query()->where('number', $reference)->whereIn('status', $usable)->where('total', '>', 0)->first();

        if ($exact instanceof Invoice) {
            return $exact;
        }

        $wanted = $this->normalise($reference);

        if ($wanted === '') {
            return null;
        }

        // Still owing first: a payment belongs on an open invoice, not a paid one.
        $open = Invoice::query()->whereIn('status', [InvoiceStatus::SENT, InvoiceStatus::OVERDUE, InvoiceStatus::PARTIALLY_PAID])
            ->where('total', '>', 0)
            ->get()
            ->first(fn (Invoice $invoice): bool => $this->normalise($invoice->number) === $wanted);

        return $open instanceof Invoice ? $open : null;
    }

    protected function normalise(string $value): string
    {
        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /**
     * @param  array<array-key, mixed>  $c2b
     */
    protected function unmatched(array $c2b, string $transId, int $amount, string $reference): void
    {
        Log::warning('Billing: paybill payment matched no invoice', ['trans_id' => $transId, 'reference' => $reference]);

        // Kept as a record so the money is never only a log line. Safaricom
        // redelivers until answered, so the transaction id is the identity.
        if (! UnmatchedPayment::query()->where('provider_payment_id', $transId)->exists()) {
            $paidAt = null;

            try {
                $paidAt = is_string($c2b['TransTime'] ?? null) ? Carbon::createFromFormat('YmdHis', $c2b['TransTime']) : null;
            } catch (\Throwable) {
                // The time is a refinement; an unreadable one must not lose the payment.
            }

            $record = new UnmatchedPayment([
                'ulid' => Str::ulid()->toBase32(),
                'provider_payment_id' => $transId,
                'provider' => 'mpesa',
                'amount' => $amount,
                'currency' => 'KES',
                'account_reference' => $reference === '' ? null : mb_substr($reference, 0, 255),
                'payer_phone' => is_string($c2b['MSISDN'] ?? null) ? $c2b['MSISDN'] : null,
                'paid_at' => $paidAt,
                'status' => UnmatchedPaymentStatus::OPEN,
            ]);
            $record->save();
        }

        PaybillPaymentUnmatched::dispatch($transId, $amount, 'KES', $reference === '' ? null : $reference, is_string($c2b['MSISDN'] ?? null) ? $c2b['MSISDN'] : null);
    }
}
