<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Moffhub\Billing\Exceptions\FeatureNotAvailableException;
use Symfony\Component\HttpFoundation\Response;

class CheckFeatureAccess
{
    /**
     * Handle an incoming request.
     *
     * Usage: Route::middleware(['feature:ocr_scanning,shifts'])
     * Multiple features use comma separation (AND logic).
     *
     * @param  string  $features  Comma-separated feature slugs
     */
    public function handle(Request $request, Closure $next, string $features): Response
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            throw FeatureNotAvailableException::noSubscription();
        }

        // Admin bypass — skip all feature checks
        if (method_exists($billable, 'isBillingAdmin') && $billable->isBillingAdmin()) {
            return $next($request);
        }

        $requiredFeatures = explode(',', $features);

        foreach ($requiredFeatures as $feature) {
            $feature = trim($feature);

            if (! $billable->hasFeature($feature)) {
                throw FeatureNotAvailableException::featureNotIncluded($feature);
            }
        }

        return $next($request);
    }

    /**
     * Resolve the billable entity from the request.
     * Override this in a subclass to customize resolution.
     */
    protected function resolveBillable(Request $request): mixed
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        // If the user model itself is billable
        if (method_exists($user, 'hasFeature')) {
            return $user;
        }

        // If the user belongs to a billable entity (e.g., company)
        $billableRelation = config('billing.billable_relation', 'company');

        if (method_exists($user, $billableRelation)) {
            return $user->{$billableRelation};
        }

        return null;
    }
}
