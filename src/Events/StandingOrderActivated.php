<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Models\StandingOrder;

/** The customer approved the standing order on their phone. */
class StandingOrderActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly StandingOrder $standingOrder) {}
}
