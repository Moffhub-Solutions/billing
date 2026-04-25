<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Moffhub\Billing\Contracts\BillableInterface;
use Moffhub\Billing\Exceptions\FeatureNotAvailableException;
use Symfony\Component\HttpFoundation\Response;

class CheckPlanAccess
{
    /**
     * Handle an incoming request.
     *
     * Usage: Route::middleware(['plan:professional,enterprise'])
     * Multiple plans use comma separation (OR logic — any plan matches).
     *
     * @param  string  $plans  Comma-separated plan slugs
     */
    public function handle(Request $request, Closure $next, string $plans): Response
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            throw FeatureNotAvailableException::noSubscription();
        }

        // Admin bypass — skip plan check
        if ($billable->isBillingAdmin()) {
            return $next($request);
        }

        $allowedPlans = array_map(trim(...), explode(',', $plans));

        foreach ($allowedPlans as $plan) {
            if ($billable->onPlan($plan)) {
                return $next($request);
            }
        }

        throw FeatureNotAvailableException::planRequired($allowedPlans);
    }

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
