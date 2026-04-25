<?php

declare(strict_types=1);

use Moffhub\Billing\Services\BillingService;

if (! function_exists('billing')) {
    /**
     * Get the billing service instance.
     */
    function billing(): BillingService
    {
        return app('billing');
    }
}

if (! function_exists('billing_table')) {
    /**
     * Resolve a billing table name from config (typed wrapper around config()).
     *
     * Migrations and queries call this to get a guaranteed-string table name
     * even when the application's config repository hasn't been narrowed by
     * static analysis.
     */
    function billing_table(string $key, string $default): string
    {
        $value = config('billing.tables.'.$key, $default);

        return is_string($value) ? $value : $default;
    }
}
