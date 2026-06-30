<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Models\PaymentProof;

/**
 * A proof of payment was rejected by a back-office reviewer (could not confirm
 * the funds). No Payment is created.
 */
class PaymentProofRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PaymentProof $proof,
        public readonly ?string $reason = null,
    ) {}
}
