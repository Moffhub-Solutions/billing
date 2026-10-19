<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

enum StandingOrderStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Active, self::Paused], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
