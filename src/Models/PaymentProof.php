<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Enums\PaymentProofStatus;

/**
 * A customer-submitted proof of an off-platform payment (an M-Pesa code, a bank
 * statement, a deposit slip) for a supported or unsupported channel. It is not
 * money until a back-office verifier confirms the funds landed, at which point a
 * completed Payment is created. Distinct from cash (the manual provider).
 *
 * @property int $id
 * @property string $ulid
 * @property string $billable_type
 * @property string $billable_id
 * @property int|null $invoice_id
 * @property int|null $subscription_id
 * @property string $channel
 * @property string|null $reference
 * @property string|null $payer_name
 * @property string|null $payer_detail
 * @property int $amount
 * @property string $currency
 * @property string|null $proof_url
 * @property PaymentProofStatus $status
 * @property string|null $notes
 * @property string|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property string|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $review_notes
 * @property int|null $payment_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PaymentProof extends Model
{
    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => PaymentProofStatus::class,
            'amount' => 'integer',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.payment_proofs', 'billing_payment_proofs');

        return is_string($value) ? $value : 'billing_payment_proofs';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isPending(): bool
    {
        return $this->status === PaymentProofStatus::PENDING;
    }
}
