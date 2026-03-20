<?php

declare(strict_types=1);

namespace Moffhub\Billing\Facades;

use Illuminate\Support\Facades\Facade;
use Moffhub\Billing\Services\BillingService;

/**
 * @method static \Moffhub\Billing\Services\BillingService forBillable(\Illuminate\Database\Eloquent\Model $billable)
 * @method static \Illuminate\Database\Eloquent\Collection plans()
 * @method static \Moffhub\Billing\Models\Plan|null plan(string $slug)
 * @method static \Illuminate\Database\Eloquent\Collection features()
 *
 * @see BillingService
 */
class Billing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'billing';
    }
}
