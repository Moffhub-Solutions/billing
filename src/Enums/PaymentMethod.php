<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

enum PaymentMethod: string
{
    case MPESA = 'mpesa';
    case AIRTEL_MONEY = 'airtel_money';
    case CARD = 'card';
    case BANK = 'bank';
    case MOBILE_MONEY = 'mobile_money';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::MPESA => 'M-Pesa',
            self::AIRTEL_MONEY => 'Airtel Money',
            self::CARD => 'Card',
            self::BANK => 'Bank Transfer',
            self::MOBILE_MONEY => 'Mobile Money',
            self::MANUAL => 'Manual/Cash',
        };
    }
}
