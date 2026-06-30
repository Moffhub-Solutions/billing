<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Moffhub\Billing\Contracts\BillableInterface;
use Moffhub\Billing\Exceptions\UsageLimitExceededException;
use Symfony\Component\HttpFoundation\Response;

class CheckUsageLimit
{
    /**
     * Handle an incoming request.
     *
     * Usage: Route::middleware(['usage:ocr_scanning'])
     * Checks if the billable is within their usage limit for the feature.
     */
    public function handle(Request $request, Closure $next, string $featureSlug): Response
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            throw UsageLimitExceededException::noSubscription();
        }

        // Admin bypass — skip usage limit check
        if ($billable->isBillingAdmin()) {
            return $next($request);
        }

        $remaining = $billable->remainingQuota($featureSlug);

        // null = unlimited, allow through
        if ($remaining === null) {
            return $next($request);
        }

        if ($remaining <= 0 && ! billing_setting('usage.allow_overage', false, $billable)) {
            throw UsageLimitExceededException::limitReached(
                $featureSlug,
                $billable->usage($featureSlug),
                $billable->usageLimit($featureSlug),
            );
        }

        return $next($request);
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
