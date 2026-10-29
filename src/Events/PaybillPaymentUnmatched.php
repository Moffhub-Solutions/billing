<?php

declare(strict_types=1);

namespace Moffhub\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money arrived on the paybill but the account number names no invoice that
 * can take it. Nothing is recorded, so the app should surface it for a person
 * to allocate (or refund) using the transaction id.
 */
class PaybillPaymentUnmatched
{
    use Dispatchable;

    public function __construct(
        public readonly string $transactionId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $accountReference,
        public readonly ?string $phone,
    ) {}
}
