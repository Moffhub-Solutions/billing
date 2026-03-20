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
