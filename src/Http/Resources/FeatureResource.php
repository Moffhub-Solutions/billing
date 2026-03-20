<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeatureResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'is_trackable' => $this->isTrackable(),
            'is_addon' => $this->is_addon,
            'addon_price' => $this->when($this->is_addon, $this->addon_price),
            'addon_formatted_price' => $this->when(
                $this->is_addon && $this->addon_price,
                fn (): string => config('billing.currency', 'KES').' '.number_format($this->addon_price / 100, 2),
            ),
            'addon_billing_cycle' => $this->when($this->is_addon, $this->addon_billing_cycle?->value),
            'is_active' => $this->is_active,
            'metadata' => $this->when($this->metadata !== null, $this->metadata),
        ];
    }
}
