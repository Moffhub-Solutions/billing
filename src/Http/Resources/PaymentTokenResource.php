<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Moffhub\Billing\Models\PaymentToken;

/**
 * @mixin PaymentToken
 */
class PaymentTokenResource extends JsonResource
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
            'token_type' => $this->token_type,
            'display_label' => $this->displayLabel(),
            'last_four' => $this->last_four,
            'card_brand' => $this->card_brand,
            'card_exp_month' => $this->card_exp_month,
            'card_exp_year' => $this->card_exp_year,
            'bank_name' => $this->bank_name,
            'phone' => $this->phone,
            'is_default' => $this->is_default,
            'is_reusable' => $this->is_reusable,
            'is_usable' => $this->isUsable(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
