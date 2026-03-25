<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Models\Payment;

class PaymentFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ?Payment $payment,
        public readonly Model $billable,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $failureReason = null,
        public readonly int $retryCount = 0,
    ) {}
}
