<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Plan;

class BillingService
{
    public function __construct(
        protected FeatureResolver $featureResolver,
        protected UsageService $usageService,
    ) {}

    /**
     * Get all active plans, ordered by sort_order.
     */
    public function plans(): Collection
    {
        return Plan::active()->ordered()->get();
    }

    /**
     * Get a specific plan by slug.
     */
    public function plan(string $slug): ?Plan
    {
        return Plan::where('slug', $slug)->first();
    }

    /**
     * Get all active features.
     */
    public function features(): Collection
    {
        return Feature::active()->get();
    }

    /**
     * Get all available add-on features.
     */
    public function addons(): Collection
    {
        return Feature::active()->addons()->get();
    }

    /**
     * Check if a billable has access to a feature.
     */
    public function hasFeature(Model $billable, string $featureSlug): bool
    {
        return $this->featureResolver->hasFeature($billable, $featureSlug);
    }

    /**
     * Get all features available to a billable.
     *
     * @return array<string>
     */
    public function availableFeatures(Model $billable): array
    {
        return $this->featureResolver->getAvailableFeatures($billable);
    }

    /**
     * Record usage for a metered feature.
     */
    public function recordUsage(
        Model $billable,
        string $featureSlug,
        int $quantity = 1,
        ?string $transactionId = null,
    ): void {
        $this->usageService->record($billable, $featureSlug, $quantity, $transactionId);
    }

    /**
     * Get current usage for a feature.
     */
    public function usage(Model $billable, string $featureSlug): int
    {
        return $this->usageService->getUsage($billable, $featureSlug);
    }

    /**
     * Clear the feature cache for a billable (e.g., after plan change).
     */
    public function clearCache(Model $billable): void
    {
        $this->featureResolver->clearCache($billable);
    }
}
