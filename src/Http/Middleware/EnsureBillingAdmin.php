<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Moffhub\Billing\Contracts\BillableInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for back-office billing routes (managing plans, features, coupons, and
 * marking invoices paid/void). Fails closed: unless the app grants admin access
 * via a configured Gate ability (`billing.admin_gate`) or an `isBillingAdmin()`
 * that returns true, the request is rejected.
 */
class EnsureBillingAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! is_object($user)) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }

        if (! $this->isAdmin($user)) {
            return response()->json(['message' => 'Billing admin privileges required.'], 403);
        }

        return $next($request);
    }

    private function isAdmin(object $user): bool
    {
        // 1. A Laravel Gate ability, when the app configures one.
        $gate = config('billing.admin_gate');

        if (is_string($gate) && $gate !== '' && Gate::forUser($user)->allows($gate)) {
            return true;
        }

        // 2. The billable's own admin check (billing.admin_bypass_method).
        if ($user instanceof BillableInterface) {
            return $user->isBillingAdmin();
        }

        $relationConfig = config('billing.billable_relation', 'company');
        $relation = is_string($relationConfig) ? $relationConfig : 'company';

        if (method_exists($user, $relation)) {
            $billable = $user->{$relation};

            if ($billable instanceof BillableInterface) {
                return $billable->isBillingAdmin();
            }
        }

        return false;
    }
}
