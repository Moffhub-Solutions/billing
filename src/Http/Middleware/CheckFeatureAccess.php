<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Moffhub\Billing\Contracts\BillableInterface;
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
        if ($billable->isBillingAdmin()) {
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
    /**
     * @return (Model&BillableInterface)|null
     */
    protected function resolveBillable(Request $request): (Model&BillableInterface)|null
    {
        $user = $request->user();

        if (! is_object($user)) {
            return null;
        }

        if ($user instanceof BillableInterface) {
            return $user;
        }

        $relationConfig = config('billing.billable_relation', 'company');
        $relation = is_string($relationConfig) ? $relationConfig : 'company';

        if (! method_exists($user, $relation)) {
            return null;
        }

        $resolved = $user->{$relation};

        if ($resolved instanceof Model && $resolved instanceof BillableInterface) {
            return $resolved;
        }

        return null;
    }
}
