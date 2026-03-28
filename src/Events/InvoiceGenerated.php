<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Carbon\CarbonInterface;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Models\Invoice;

class InvoiceGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly Model $billable,
        public readonly string $invoiceNumber,
        public readonly int $total,
        public readonly string $currency,
        public readonly ?CarbonInterface $dueDate = null,
    ) {}
}
