<?php

declare(strict_types=1);

namespace Moffhub\Billing\Exceptions;

use RuntimeException;

class PaymentFailedException extends RuntimeException
{
    public function __construct(
        string $message = 'Payment failed',
        public readonly ?string $provider = null,
        public readonly ?string $providerMessage = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function fromProvider(string $provider, string $providerMessage): self
    {
        return new self(
            "Payment via {$provider} failed: {$providerMessage}",
            $provider,
            $providerMessage,
        );
    }
}
