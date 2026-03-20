<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'plan' => $this->whenLoaded('plan', fn (): PlanResource => new PlanResource($this->plan)),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'is_active' => $this->isActive(),
            'on_trial' => $this->onTrial(),
            'on_grace_period' => $this->onGracePeriod(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601ZuluString(),
            'current_period_start' => $this->current_period_start?->toIso8601ZuluString(),
            'current_period_end' => $this->current_period_end?->toIso8601ZuluString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'paused_at' => $this->paused_at?->toIso8601ZuluString(),
            'payment_provider' => $this->payment_provider,
            'addons' => SubscriptionAddonResource::collection($this->whenLoaded('addons')),
            'metadata' => $this->when($this->metadata !== null, $this->metadata),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
