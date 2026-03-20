<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        $allowedPlans = array_map(trim(...), explode(',', $plans));

        foreach ($allowedPlans as $plan) {
            if ($billable->onPlan($plan)) {
                return $next($request);
            }
        }

        throw FeatureNotAvailableException::planRequired($allowedPlans);
    }

    protected function resolveBillable(Request $request): mixed
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (method_exists($user, 'onPlan')) {
            return $user;
        }

        $billableRelation = config('billing.billable_relation', 'company');

        if (method_exists($user, $billableRelation)) {
            return $user->{$billableRelation};
        }

        return null;
    }
}
