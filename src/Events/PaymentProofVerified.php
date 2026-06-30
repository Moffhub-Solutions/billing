<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\PaymentProof;

/**
 * A proof of payment was verified by a back-office reviewer and settled into a
 * completed Payment.
 */
class PaymentProofVerified
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PaymentProof $proof,
        public readonly Payment $payment,
    ) {}
}
