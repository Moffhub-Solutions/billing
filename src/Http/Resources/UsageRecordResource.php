<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsageRecordResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'feature_slug' => $this->feature_slug,
            'usage_count' => $this->usage_count,
            'usage_limit' => $this->usage_limit,
            'remaining' => $this->remaining(),
            'percentage' => $this->usagePercentage(),
            'is_within_limit' => $this->isWithinLimit(),
            'overage_count' => $this->overage_count,
            'period_start' => $this->period_start?->toIso8601ZuluString(),
            'period_end' => $this->period_end?->toIso8601ZuluString(),
        ];
    }
}
