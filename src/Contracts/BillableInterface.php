<?php

declare(strict_types=1);

namespace Moffhub\Billing\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Services\SubscriptionBuilder;

interface BillableInterface
{
    /**
     * Start building a new subscription for this billable.
     */
    public function subscribe(string $planSlug): SubscriptionBuilder;

    /**
     * Get the active subscription (or null).
     */
    public function subscription(): ?Subscription;

    /**
     * Get all subscriptions for this billable.
     */
    public function subscriptions(): MorphMany;

    /**
     * Check if the billable has an active subscription.
     */
    public function subscribed(): bool;

    /**
     * Check if the billable is on a specific plan.
     */
    public function onPlan(string $planSlug): bool;

    /**
     * Check if the billable is currently on a trial.
     */
    public function onTrial(): bool;

    /**
     * Check if the billable has access to a specific feature.
     */
    public function hasFeature(string $featureSlug): bool;

    /**
     * Check if the billable has access to a feature, or is an admin that bypasses gating.
     */
    public function hasFeatureOrAdmin(string $featureSlug): bool;

    /**
     * Check if this billable is considered an admin for billing bypass purposes.
     */
    public function isBillingAdmin(): bool;

    /**
     * Get the usage count for a metered feature in the current period.
     */
    public function usage(string $featureSlug): int;

    /**
     * Get the usage limit for a feature, or null if unlimited.
     */
    public function usageLimit(string $featureSlug): ?int;

    /**
     * Get the remaining quota for a metered feature.
     */
    public function remainingQuota(string $featureSlug): ?int;
}
