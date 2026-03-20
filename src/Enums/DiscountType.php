<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

enum DiscountType: string
{
    case PERCENT = 'percent';
    case FIXED = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::PERCENT => 'Percentage',
            self::FIXED => 'Fixed Amount',
        };
    }
}
