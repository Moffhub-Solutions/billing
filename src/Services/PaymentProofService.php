<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Moffhub\Billing\Enums\PaymentMethod;
use Moffhub\Billing\Enums\PaymentProofStatus;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Events\PaymentProofRejected;
use Moffhub\Billing\Events\PaymentProofSubmitted;
use Moffhub\Billing\Events\PaymentProofVerified;
use Moffhub\Billing\Events\PaymentReceived;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\PaymentProof;

/**
 * Records and adjudicates off-platform proofs of payment.
 *
 * Submitting a proof never moves money; only verification (a separate
 * back-office action) creates a completed Payment and settles the invoice. The
 * submit/verify/reject steps each emit an event so any maker-checker / approval
 * system can require that the verifier differ from the submitter.
 */
class PaymentProofService
{
    /**
     * Record a pending proof of payment for a billable.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(Model $billable, array $data): PaymentProof
    {
        $channel = isset($data['channel']) && is_string($data['channel']) ? trim($data['channel']) : '';

        if ($channel === '') {
            throw new InvalidArgumentException('A payment-proof channel is required.');
        }

        $proof = new PaymentProof([
            'ulid' => Str::ulid()->toBase32(),
            'invoice_id' => $data['invoice_id'] ?? null,
            'subscription_id' => $data['subscription_id'] ?? null,
            'channel' => $channel,
            'reference' => $data['reference'] ?? null,
            'payer_name' => $data['payer_name'] ?? null,
            'payer_detail' => $data['payer_detail'] ?? null,
            'amount' => is_numeric($data['amount'] ?? null) ? (int) $data['amount'] : 0,
            'currency' => is_string($data['currency'] ?? null) ? $data['currency'] : 'KES',
            'proof_url' => $data['proof_url'] ?? null,
            'status' => PaymentProofStatus::PENDING,
            'notes' => $data['notes'] ?? null,
            'submitted_by' => $data['submitted_by'] ?? null,
            'submitted_at' => now(),
        ]);

        $billable->morphMany(PaymentProof::class, 'billable')->save($proof);

        PaymentProofSubmitted::dispatch($proof);

        return $proof;
    }

    /**
     * Verify a pending proof: create a completed Payment (offline channel),
     * settle the linked invoice, and fire the settlement + verified events.
     */
    public function verify(PaymentProof $proof, ?string $verifiedBy = null, ?string $notes = null): PaymentProof
    {
        if (! $proof->isPending()) {
            throw new InvalidArgumentException('Only pending proofs of payment can be verified.');
        }

        return DB::transaction(function () use ($proof, $verifiedBy, $notes): PaymentProof {
            $locked = PaymentProof::query()->lockForUpdate()->find($proof->id);

            // Idempotency: another verifier may have settled it first.
            if (! $locked instanceof PaymentProof || ! $locked->isPending()) {
                return $locked instanceof PaymentProof ? $locked : $proof;
            }

            $billable = $locked->billable;

            if (! $billable instanceof Model) {
                throw new InvalidArgumentException('Payment proof has no billable to credit.');
            }

            $payment = new Payment([
                'ulid' => Str::ulid()->toBase32(),
                'invoice_id' => $locked->invoice_id,
                'subscription_id' => $locked->subscription_id,
                'amount' => $locked->amount,
                'currency' => $locked->currency,
                'status' => PaymentStatus::COMPLETED,
                'payment_provider' => 'offline',
                'payment_method' => PaymentMethod::OFFLINE,
                'provider_reference' => $locked->reference,
                'paid_at' => now(),
                'metadata' => [
                    'payment_proof_id' => $locked->id,
                    'channel' => $locked->channel,
                    'verified_by' => $verifiedBy,
                ],
            ]);

            $billable->morphMany(Payment::class, 'billable')->save($payment);

            $locked->update([
                'status' => PaymentProofStatus::VERIFIED,
                'verified_by' => $verifiedBy,
                'verified_at' => now(),
                'review_notes' => $notes,
                'payment_id' => $payment->id,
            ]);

            // Settle the linked invoice (currency-scoped in amountPaid()).
            if ($payment->invoice_id !== null) {
                $invoice = Invoice::query()->lockForUpdate()->find($payment->invoice_id);
                $invoice?->recalculateStatus();
            }

            PaymentReceived::dispatch(
                $payment,
                $billable,
                (int) $payment->amount,
                (string) $payment->currency,
                PaymentMethod::OFFLINE->value,
                $payment->provider_reference,
            );

            PaymentProofVerified::dispatch($locked, $payment);

            return $locked;
        });
    }

    /**
     * Reject a pending proof: no Payment is created.
     */
    public function reject(PaymentProof $proof, ?string $rejectedBy = null, ?string $reason = null): PaymentProof
    {
        if (! $proof->isPending()) {
            throw new InvalidArgumentException('Only pending proofs of payment can be rejected.');
        }

        $proof->update([
            'status' => PaymentProofStatus::REJECTED,
            'verified_by' => $rejectedBy,
            'verified_at' => now(),
            'review_notes' => $reason,
        ]);

        PaymentProofRejected::dispatch($proof, $reason);

        return $proof;
    }
}
