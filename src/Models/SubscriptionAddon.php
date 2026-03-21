<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Moffhub\Billing\Database\Factories\SubscriptionAddonFactory;

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
        return config('billing.tables.subscription_addons', 'billing_subscription_addons');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
