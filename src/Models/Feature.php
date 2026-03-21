<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Moffhub\Billing\Database\Factories\FeatureFactory;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\FeatureType;

class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory;

    protected static function newFactory(): FeatureFactory
    {
        return FeatureFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => FeatureType::class,
            'is_addon' => 'boolean',
            'addon_price' => 'integer',
            'addon_billing_cycle' => BillingCycle::class,
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return config('billing.tables.features', 'billing_features');
    }

    /**
     * Scope to only active features.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to only add-on features.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAddons(Builder $query): Builder
    {
        return $query->where('is_addon', true);
    }

    /**
     * Check if this feature tracks usage.
     */
    public function isTrackable(): bool
    {
        return $this->type->isTrackable();
    }
}
