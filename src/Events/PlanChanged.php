<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;

class PlanChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Model $billable,
        public readonly Plan $oldPlan,
        public readonly Plan $newPlan,
        public readonly ?int $prorationAmount = null,
    ) {}
}
