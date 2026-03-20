<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        $remaining = $billable->remainingQuota($featureSlug);

        // null = unlimited, allow through
        if ($remaining === null) {
            return $next($request);
        }

        if ($remaining <= 0 && ! config('billing.usage.allow_overage', false)) {
            throw UsageLimitExceededException::limitReached(
                $featureSlug,
                $billable->usage($featureSlug),
                $billable->usageLimit($featureSlug),
            );
        }

        return $next($request);
    }

    protected function resolveBillable(Request $request): mixed
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (method_exists($user, 'remainingQuota')) {
            return $user;
        }

        $billableRelation = config('billing.billable_relation', 'company');

        if (method_exists($user, $billableRelation)) {
            return $user->{$billableRelation};
        }

        return null;
    }
}
