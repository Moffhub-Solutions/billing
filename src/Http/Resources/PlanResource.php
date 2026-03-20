<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'base_price' => $this->base_price,
            'formatted_price' => $this->currency.' '.number_format($this->base_price / 100, 2),
            'currency' => $this->currency,
            'billing_cycle' => $this->billing_cycle?->value,
            'billing_cycle_label' => $this->billing_cycle?->label(),
            'monthly_price' => $this->monthlyPrice(),
            'trial_days' => $this->trial_days,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'features' => $this->features ?? [],
            'limits' => $this->limits ?? [],
            'metadata' => $this->when($this->metadata !== null, $this->metadata),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
