<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum StandingOrderFrequency: string
{
    case OneOff = 'one_off';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case BiMonthly = 'bi_monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    /**
     * Safaricom Daraja frequency code (M-Pesa Ratiba "Frequency" field).
     */
    public function safaricomCode(): string
    {
        return match ($this) {
            self::OneOff => '1',
            self::Daily => '2',
            self::Weekly => '3',
            self::Monthly => '4',
            self::BiMonthly => '5',
            self::Quarterly => '6',
            self::HalfYearly => '7',
            self::Yearly => '8',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OneOff => 'One-off',
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::BiMonthly => 'Every 2 months',
            self::Quarterly => 'Quarterly',
            self::HalfYearly => 'Every 6 months',
            self::Yearly => 'Yearly',
        };
    }

    public static function fromBillingCycle(BillingCycle $cycle): self
    {
        return match ($cycle) {
            BillingCycle::MONTHLY => self::Monthly,
            BillingCycle::QUARTERLY => self::Quarterly,
            BillingCycle::ANNUAL => self::Yearly,
        };
    }

    /**
     * The next scheduled debit after $from, or null for a one-off order.
     */
    public function nextAfter(CarbonInterface $from): ?CarbonInterface
    {
        $date = CarbonImmutable::instance($from);

        return match ($this) {
            self::OneOff => null,
            self::Daily => $date->addDay(),
            self::Weekly => $date->addWeek(),
            self::Monthly => $date->addMonthNoOverflow(),
            self::BiMonthly => $date->addMonthsNoOverflow(2),
            self::Quarterly => $date->addMonthsNoOverflow(3),
            self::HalfYearly => $date->addMonthsNoOverflow(6),
            self::Yearly => $date->addYearNoOverflow(),
        };
    }
}
