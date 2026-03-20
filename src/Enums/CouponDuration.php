<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

enum CouponDuration: string
{
    case ONCE = 'once';
    case REPEATING = 'repeating';
    case FOREVER = 'forever';

    public function label(): string
    {
        return match ($this) {
            self::ONCE => 'Once',
            self::REPEATING => 'Multiple Months',
            self::FOREVER => 'Forever',
        };
    }
}
