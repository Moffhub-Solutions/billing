<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionAddonResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'feature' => $this->whenLoaded('feature', fn (): FeatureResource => new FeatureResource($this->feature)),
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'price_override' => $this->price_override,
            'enabled_at' => $this->enabled_at?->toIso8601ZuluString(),
            'disabled_at' => $this->disabled_at?->toIso8601ZuluString(),
        ];
    }
}
