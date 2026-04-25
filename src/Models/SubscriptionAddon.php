<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Database\Factories\SubscriptionAddonFactory;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int $feature_id
 * @property string $status
 * @property int|null $price_override
 * @property Carbon|null $enabled_at
 * @property Carbon|null $disabled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Subscription $subscription
 * @property-read Feature $feature
 */
class SubscriptionAddon extends Model
{
    /** @use HasFactory<SubscriptionAddonFactory> */
    use HasFactory;

    protected static function newFactory(): SubscriptionAddonFactory
    {
        return SubscriptionAddonFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => 'string',
            'price_override' => 'integer',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.subscription_addons', 'billing_subscription_addons');

        return is_string($value) ? $value : 'billing_subscription_addons';
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Feature, $this>
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
