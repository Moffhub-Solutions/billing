<?php

declare(strict_types=1);

namespace Moffhub\Billing\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class TransactionLimitException extends HttpException
{
    public function __construct(
        string $message = 'Transaction limit exceeded',
        public readonly ?string $provider = null,
        public readonly ?int $amount = null,
        public readonly ?int $tranchesNeeded = null,
        public readonly ?int $tranchesAllowed = null,
    ) {
        parent::__construct(422, $message);
    }

    public static function dailyCapExceeded(string $provider, int $amount, int $tranchesNeeded, int $tranchesAllowed): self
    {
        return new self(
            "Amount cannot be collected via '{$provider}': it requires {$tranchesNeeded} transactions but only {$tranchesAllowed} remain within the daily limit.",
            $provider,
            $amount,
            $tranchesNeeded,
            $tranchesAllowed,
        );
    }
}
