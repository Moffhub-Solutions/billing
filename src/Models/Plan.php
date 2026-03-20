<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Moffhub\Billing\Enums\BillingCycle;

class Plan extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'base_price' => 'integer',
            'billing_cycle' => BillingCycle::class,
            'trial_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return config('billing.tables.plans', 'billing_plans');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Check if this plan includes a specific feature.
     */
    public function hasFeature(string $featureSlug): bool
    {
        return in_array($featureSlug, $this->features ?? [], true);
    }

    /**
     * Get the limit for a specific feature, or null if unlimited.
     */
    public function getLimit(string $key): ?int
    {
        $limits = $this->limits ?? [];

        return isset($limits[$key]) ? (int) $limits[$key] : null;
    }

    /**
     * Scope to only active plans.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort_order.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Get the monthly equivalent price for comparison.
     */
    public function monthlyPrice(): int
    {
        return match ($this->billing_cycle) {
            BillingCycle::MONTHLY => $this->base_price,
            BillingCycle::QUARTERLY => (int) round($this->base_price / 3),
            BillingCycle::ANNUAL => (int) round($this->base_price / 12),
            default => $this->base_price,
        };
    }
}
