<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Moffhub\Billing\Models\UnmatchedPayment;

/**
 * @mixin UnmatchedPayment
 */
class UnmatchedPaymentResource extends JsonResource
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
            'provider' => $this->provider,
            'provider_payment_id' => $this->provider_payment_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'account_reference' => $this->account_reference,
            'payer_phone' => $this->payer_phone,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'payment_id' => $this->payment_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
