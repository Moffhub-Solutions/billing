<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Models\StandingOrder;
use Moffhub\Billing\Models\StandingOrderExecution;

/** Creation was rejected, a debit failed, or a scheduled debit was missed. */
class StandingOrderFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly StandingOrder $standingOrder,
        public readonly ?StandingOrderExecution $execution = null,
        public readonly ?string $reason = null,
        public readonly int $failureCount = 0,
    ) {}
}
