<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Database\Factories\UsageEventFactory;

/**
 * @property int $id
 * @property string|null $ulid
 * @property string $billable_type
 * @property int $billable_id
 * @property string $feature_slug
 * @property int $quantity
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $properties
 * @property Carbon $recorded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model $billable
 */
class UsageEvent extends Model
{
    /** @use HasFactory<UsageEventFactory> */
    use HasFactory;

    protected static function newFactory(): UsageEventFactory
    {
        return UsageEventFactory::new();
    }

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
        $value = config('billing.tables.usage_events', 'billing_usage_events');

        return is_string($value) ? $value : 'billing_usage_events';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
