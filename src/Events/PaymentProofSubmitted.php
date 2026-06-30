<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\Billing\Models\PaymentProof;

/**
 * A proof of payment was recorded and awaits verification. Hook this to drive
 * any maker-checker / approval workflow the consuming app already uses.
 */
class PaymentProofSubmitted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PaymentProof $proof,
    ) {}
}
