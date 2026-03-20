<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

enum BillingCycle: string
{
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case ANNUAL = 'annual';

    public function days(): int
    {
        return match ($this) {
            self::MONTHLY => 30,
            self::QUARTERLY => 90,
            self::ANNUAL => 365,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MONTHLY => 'Monthly',
            self::QUARTERLY => 'Quarterly',
            self::ANNUAL => 'Annual',
        };
    }
}
