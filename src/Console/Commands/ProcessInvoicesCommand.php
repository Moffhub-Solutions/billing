<?php

declare(strict_types=1);

namespace Moffhub\Billing\Console\Commands;

use Illuminate\Console\Command;
use Moffhub\Billing\Services\InvoiceService;

class ProcessInvoicesCommand extends Command
{
    protected $signature = 'billing:process-invoices';

    protected $description = 'Mark overdue invoices and generate upcoming subscription invoices';

    public function handle(InvoiceService $invoiceService): int
    {
        $this->info('Processing invoices...');

        // Mark overdue invoices
        $overdueCount = $invoiceService->markOverdue();
        $this->info("Marked {$overdueCount} invoice(s) as overdue.");

        $this->info('Invoice processing complete.');

        return self::SUCCESS;
    }
}
