<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Moffhub\Billing\Contracts\FeatureResolverInterface;

class FeatureResolver implements FeatureResolverInterface
{
    /**
     * Check if the billable has access to a feature.
     */
    public function hasFeature(Model $billable, string $featureSlug): bool
    {
        $ttlRaw = billing_setting('features.cache_ttl', 300, $billable);
        $ttl = is_numeric($ttlRaw) ? (int) $ttlRaw : 300;
        $prefix = $this->cachePrefix();

        $billableKey = $billable->getKey();
        $key = (is_string($billableKey) || is_int($billableKey)) ? (string) $billableKey : '';
        $cacheKey = "{$prefix}:{$billable->getMorphClass()}:{$key}:{$featureSlug}";

        if ($ttl > 0) {
            /** @var bool $cached */
            $cached = Cache::remember($cacheKey, $ttl, fn (): bool => $this->resolveFeature($billable, $featureSlug));

            return $cached;
        }

        return $this->resolveFeature($billable, $featureSlug);
    }

    /**
     * Get the usage limit for a feature.
     */
    public function getLimit(Model $billable, string $featureSlug): ?int
    {
        if (! method_exists($billable, 'subscription')) {
            return 0;
        }

        $subscription = $billable->subscription();

        if ($subscription === null) {
            return 0;
        }

        return $subscription->plan->getLimit($featureSlug);
    }

    /**
     * Get all available feature slugs for the billable.
     *
     * @return array<int, string>
     */
    public function getAvailableFeatures(Model $billable): array
    {
        if (! method_exists($billable, 'subscription')) {
            return [];
        }

        $subscription = $billable->subscription();

        if ($subscription === null) {
            return [];
        }

        $planFeatures = $subscription->plan->features ?? [];

        $addonFeatures = $subscription->addons()
            ->where('status', 'active')
            ->with('feature')
            ->get()
            ->pluck('feature.slug')
            ->all();

        $merged = array_values(array_unique(array_merge($planFeatures, $addonFeatures)));

        $strings = [];
        foreach ($merged as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * Clear the feature cache for a billable.
     */
    public function clearCache(Model $billable): void
    {
        $prefix = $this->cachePrefix();
        $billableKey = $billable->getKey();
        $key = (is_string($billableKey) || is_int($billableKey)) ? (string) $billableKey : '';

        // Clear all feature caches for this billable
        $features = $this->getAvailableFeatures($billable);

        foreach ($features as $feature) {
            Cache::forget("{$prefix}:{$billable->getMorphClass()}:{$key}:{$feature}");
        }
    }

    /**
     * Actually resolve whether the billable has the feature.
     */
    protected function resolveFeature(Model $billable, string $featureSlug): bool
    {
        // Admin bypass
        if (method_exists($billable, 'isBillingAdmin') && $billable->isBillingAdmin() === true) {
            return true;
        }

        if (! method_exists($billable, 'subscription')) {
            return false;
        }

        $subscription = $billable->subscription();

        if ($subscription === null) {
            return false;
        }

        return $subscription->hasFeature($featureSlug);
    }

    private function cachePrefix(): string
    {
        $prefix = config('billing.features.cache_prefix', 'billing_features');

        return is_string($prefix) ? $prefix : 'billing_features';
    }
}
