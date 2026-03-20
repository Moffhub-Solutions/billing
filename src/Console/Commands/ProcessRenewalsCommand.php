<?php

declare(strict_types=1);

namespace Moffhub\Billing\Console\Commands;

use Illuminate\Console\Command;
use Moffhub\Billing\Jobs\ProcessDunning;
use Moffhub\Billing\Jobs\ProcessRenewals;
use Moffhub\Billing\Jobs\ProcessTrialConversions;

class ProcessRenewalsCommand extends Command
{
    protected $signature = 'billing:process-renewals
                            {--trials : Also process trial conversions}
                            {--dunning : Also process dunning retries}
                            {--all : Process renewals, trials, and dunning}
                            {--sync : Run synchronously instead of dispatching to queue}';

    protected $description = 'Process subscription renewals, trial conversions, and dunning retries';

    public function handle(): int
    {
        $sync = $this->option('sync');
        $all = $this->option('all');

        $this->info('Processing subscription renewals...');

        if ($sync) {
            app()->call([new ProcessRenewals, 'handle']);
        } else {
            ProcessRenewals::dispatch();
        }

        $this->info('Renewal processing dispatched.');

        if ($all || $this->option('trials')) {
            $this->info('Processing trial conversions...');

            if ($sync) {
                app()->call([new ProcessTrialConversions, 'handle']);
            } else {
                ProcessTrialConversions::dispatch();
            }

            $this->info('Trial conversion processing dispatched.');
        }

        if ($all || $this->option('dunning')) {
            $this->info('Processing dunning retries...');

            if ($sync) {
                app()->call([new ProcessDunning, 'handle']);
            } else {
                ProcessDunning::dispatch();
            }

            $this->info('Dunning processing dispatched.');
        }

        return self::SUCCESS;
    }
}
