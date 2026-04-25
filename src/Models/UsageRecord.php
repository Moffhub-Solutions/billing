<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Database\Factories\UsageRecordFactory;

/**
 * @property int $id
 * @property string $billable_type
 * @property int $billable_id
 * @property string|null $ulid
 * @property string $feature_slug
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $usage_count
 * @property int|null $usage_limit
 * @property int $overage_count
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model $billable
 *
 * @method static Builder<self> currentPeriod()
 */
class UsageRecord extends Model
{
    /** @use HasFactory<UsageRecordFactory> */
    use HasFactory;

    protected static function newFactory(): UsageRecordFactory
    {
        return UsageRecordFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'usage_count' => 'integer',
            'usage_limit' => 'integer',
            'overage_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.usage_records', 'billing_usage_records');

        return is_string($value) ? $value : 'billing_usage_records';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the percentage of the limit used (0.0 to 1.0+).
     */
    public function usagePercentage(): ?float
    {
        if ($this->usage_limit === null || $this->usage_limit === 0) {
            return null;
        }

        return $this->usage_count / $this->usage_limit;
    }

    /**
     * Get remaining quota.
     */
    public function remaining(): ?int
    {
        if ($this->usage_limit === null) {
            return null;
        }

        return max(0, $this->usage_limit - $this->usage_count);
    }

    /**
     * Check if usage is within the limit.
     */
    public function isWithinLimit(): bool
    {
        if ($this->usage_limit === null) {
            return true;
        }

        return $this->usage_count < $this->usage_limit;
    }

    /**
     * Scope to current period records.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrentPeriod(Builder $query): Builder
    {
        return $query->where('period_start', '<=', now())
            ->where('period_end', '>=', now());
    }
}
