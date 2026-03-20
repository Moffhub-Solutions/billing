<?php

declare(strict_types=1);

namespace Moffhub\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

interface FeatureResolverInterface
{
    /**
     * Check if the billable has access to a feature.
     * Checks plan features + active add-ons.
     */
    public function hasFeature(Model $billable, string $featureSlug): bool;

    /**
     * Get the usage limit for a feature for this billable.
     * Returns null if unlimited.
     */
    public function getLimit(Model $billable, string $featureSlug): ?int;

    /**
     * Get all feature slugs available to the billable.
     *
     * @return array<string>
     */
    public function getAvailableFeatures(Model $billable): array;
}
