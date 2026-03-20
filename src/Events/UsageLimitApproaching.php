<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UsageLimitApproaching
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Model $billable,
        public readonly string $featureSlug,
        public readonly int $currentUsage,
        public readonly int $limit,
        public readonly float $percentage,
    ) {}
}
