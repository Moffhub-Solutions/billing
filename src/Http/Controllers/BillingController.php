<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Moffhub\Billing\Contracts\BillableInterface;

abstract class BillingController extends Controller
{
    /**
     * Resolve the billable entity from the request.
     *
     * The user attached to the request may be:
     *   - itself a Billable model (uses Billable trait, implements BillableInterface), OR
     *   - have a relation (configured via billing.billable_relation) that points
     *     to the Billable model (e.g., $user->company).
     *
     * Returns the billable Model implementing BillableInterface, or null if not
     * resolvable.
     *
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
