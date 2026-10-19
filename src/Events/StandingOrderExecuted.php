<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\StandingOrder;
use Moffhub\Billing\Models\StandingOrderExecution;

/** A scheduled debit succeeded. Fired once per provider transaction id. */
class StandingOrderExecuted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly StandingOrder $standingOrder,
        public readonly StandingOrderExecution $execution,
        public readonly ?Payment $payment = null,
    ) {}
}
