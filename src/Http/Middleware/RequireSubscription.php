<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Moffhub\Billing\Contracts\BillableInterface;
use Moffhub\Billing\Exceptions\FeatureNotAvailableException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate any route on the billable having an active subscription.
 *
 * Sibling to `feature:` and `plan:` — those check granular access, this is
 * the broad "do you have a paid subscription at all?" check that every
 * consumer with a generic dashboard gate previously had to roll themselves.
 *
 * Usage: `Route::middleware(['subscribed'])->group(...)`.
 *
 * Honors the `isBillingAdmin()` bypass on the billable, matching the rest
 * of the package's middleware.
 */
class RequireSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            throw FeatureNotAvailableException::noSubscription();
        }

        if ($billable->isBillingAdmin()) {
            return $next($request);
        }

        // Use the subscription model's `isActive()` (not the Billable trait's
        // `subscribed()`) so that an `ACTIVE` row whose `current_period_end`
        // has already passed still blocks access — the renewal job hasn't run
        // yet, but the customer's paid window is over.
        $subscription = $billable->subscription();

        if ($subscription === null || ! $subscription->isActive()) {
            throw FeatureNotAvailableException::noSubscription();
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
