<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total' => $this->total,
            'feature_slug' => $this->feature_slug,
            'period_start' => $this->period_start?->toIso8601ZuluString(),
            'period_end' => $this->period_end?->toIso8601ZuluString(),
        ];
    }
}
