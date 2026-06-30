<?php

declare(strict_types=1);

namespace Moffhub\Billing\Facades;

use Illuminate\Support\Facades\Facade;
use Moffhub\Billing\Services\BillingSettings as BillingSettingsService;

/**
 * @method static mixed get(string $key, mixed $default = null, \Illuminate\Database\Eloquent\Model|null $billable = null)
 * @method static void set(string $key, mixed $value, \Illuminate\Database\Eloquent\Model|null $billable = null)
 * @method static void forget(string $key, \Illuminate\Database\Eloquent\Model|null $billable = null)
 * @method static array<string, mixed> overrides(\Illuminate\Database\Eloquent\Model|null $billable = null)
 * @method static list<string> overridableKeys()
 * @method static bool isOverridable(string $key)
 *
 * @see BillingSettingsService
 */
class BillingSettings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'billing.settings';
    }
}
