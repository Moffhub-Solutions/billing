<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Moffhub\Billing\Models\PaymentProof;

/**
 * @mixin PaymentProof
 */
class PaymentProofResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ulid' => $this->ulid,
            'invoice_id' => $this->invoice_id,
            'subscription_id' => $this->subscription_id,
            'channel' => $this->channel,
            'reference' => $this->reference,
            'payer_name' => $this->payer_name,
            'payer_detail' => $this->payer_detail,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'proof_url' => $this->proof_url,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            'submitted_by' => $this->submitted_by,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'review_notes' => $this->review_notes,
            'payment_id' => $this->payment_id,
        ];
    }
}
