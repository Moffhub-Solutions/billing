<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'amount' => $this->amount,
            'formatted_amount' => $this->formattedAmount(),
            'currency' => $this->currency,
            'status' => $this->status?->value,
            'payment_provider' => $this->payment_provider,
            'provider_payment_id' => $this->provider_payment_id,
            'provider_reference' => $this->provider_reference,
            'payment_method' => $this->payment_method?->value,
            'payment_method_label' => $this->payment_method?->label(),
            'subscription_id' => $this->subscription_id,
            'invoice_id' => $this->invoice_id,
            'metadata' => $this->when($this->metadata !== null, $this->metadata),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'failed_at' => $this->failed_at?->toIso8601ZuluString(),
            'refunded_at' => $this->refunded_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
