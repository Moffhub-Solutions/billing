<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UsageEvent extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'properties' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return config('billing.tables.usage_events', 'billing_usage_events');
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
