<?php

declare(strict_types=1);

namespace Moffhub\Billing\Console\Commands;

use Illuminate\Console\Command;
use Moffhub\Billing\Services\StandingOrderService;

class ReconcileStandingOrdersCommand extends Command
{
    protected $signature = 'billing:reconcile-standing-orders {--provider= : Limit to one provider (e.g. mpesa)}';

    protected $description = 'Reconcile pending and active standing orders (expire, pick up cancellations, flag missed debits)';

    public function handle(StandingOrderService $service): int
    {
        $provider = $this->option('provider');
        $summary = $service->reconcile(is_string($provider) && $provider !== '' ? $provider : null);

        foreach ($summary as $key => $count) {
            $this->line(sprintf('%-10s %d', $key, $count));
        }

        return self::SUCCESS;
    }
}
