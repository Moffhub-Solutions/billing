<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UsageRecord extends Model
{
    use HasFactory;

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
        return config('billing.tables.usage_records', 'billing_usage_records');
    }

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
