<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'description' => $this->discountDescription(),
            'currency' => $this->currency,
            'duration' => $this->duration?->value,
            'duration_label' => $this->duration?->label(),
            'duration_in_months' => $this->duration_in_months,
            'max_redemptions' => $this->max_redemptions,
            'times_redeemed' => $this->times_redeemed,
            'redeem_by' => $this->redeem_by?->toIso8601ZuluString(),
            'is_active' => $this->is_active,
            'is_redeemable' => $this->isRedeemable(),
            'applies_to_plans' => $this->applies_to_plans,
            'promotion_codes' => $this->whenLoaded('promotionCodes', fn () => $this->promotionCodes->map(fn ($code): array => [
                'id' => $code->id,
                'code' => $code->code,
                'is_active' => $code->is_active,
                'first_time_transaction' => $code->first_time_transaction,
                'minimum_amount' => $code->minimum_amount,
                'max_redemptions' => $code->max_redemptions,
                'times_redeemed' => $code->times_redeemed,
                'expires_at' => $code->expires_at?->toIso8601ZuluString(),
            ])),
            'metadata' => $this->when($this->metadata !== null, $this->metadata),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
