<?php

declare(strict_types=1);

namespace Moffhub\Billing\Exceptions;

use RuntimeException;

class CouponException extends RuntimeException
{
    public static function invalidCode(string $code): self
    {
        return new self("The promotion code '{$code}' is not valid.");
    }

    public static function expired(string $identifier): self
    {
        return new self("The coupon or promotion code '{$identifier}' has expired or reached its redemption limit.");
    }

    /**
     * @param  array<string>  $errors
     */
    public static function restrictionsFailed(array $errors): self
    {
        return new self(implode(' ', $errors));
    }

    public static function notApplicableToPlan(string $planName): self
    {
        return new self("This coupon does not apply to the '{$planName}' plan.");
    }
}
