<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Moffhub\Billing\Enums\SubscriptionStatus;

class Subscription extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return config('billing.tables.subscriptions', 'billing_subscriptions');
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Check if this subscription is currently active (including trialing).
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Check if this subscription is on a trial.
     */
    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::TRIALING
            && $this->trial_ends_at?->isFuture();
    }

    /**
     * Check if this subscription has been cancelled.
     */
    public function cancelled(): bool
    {
        return $this->status === SubscriptionStatus::CANCELLED;
    }

    /**
     * Check if this subscription is past due.
     */
    public function pastDue(): bool
    {
        return $this->status === SubscriptionStatus::PAST_DUE;
    }

    /**
     * Check if this subscription has expired (period ended).
     */
    public function expired(): bool
    {
        return $this->current_period_end?->isPast() ?? false;
    }

    /**
     * Check if the subscription is on a grace period (cancelled but period not ended).
     */
    public function onGracePeriod(): bool
    {
        return $this->cancelled_at !== null
            && $this->current_period_end?->isFuture();
    }

    /**
     * Check if this subscription includes a specific feature.
     */
    public function hasFeature(string $featureSlug): bool
    {
        // Check plan features
        if ($this->plan->hasFeature($featureSlug)) {
            return true;
        }

        // Check active add-ons
        return $this->addons()
            ->where('status', 'active')
            ->whereHas('feature', fn ($q) => $q->where('slug', $featureSlug))
            ->exists();
    }

    /**
     * Cancel the subscription.
     */
    public function cancel(bool $immediately = false): self
    {
        $this->cancelled_at = now();

        if ($immediately) {
            $this->status = SubscriptionStatus::CANCELLED;
        }

        $this->save();

        return $this;
    }

    /**
     * Pause the subscription.
     */
    public function pause(): self
    {
        $this->update([
            'status' => SubscriptionStatus::PAUSED,
            'paused_at' => now(),
        ]);

        return $this;
    }

    /**
     * Resume a paused subscription.
     */
    public function resume(): self
    {
        $this->update([
            'status' => SubscriptionStatus::ACTIVE,
            'paused_at' => null,
            'resumed_at' => now(),
        ]);

        return $this;
    }

    /**
     * Scope to only active subscriptions.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::TRIALING,
        ]);
    }
}
