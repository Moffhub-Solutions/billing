<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

enum SubscriptionStatus: string
{
    case ACTIVE = 'active';
    case TRIALING = 'trialing';
    case PAST_DUE = 'past_due';
    case CANCELLED = 'cancelled';
    case PAUSED = 'paused';
    case EXPIRED = 'expired';

    public function isActive(): bool
    {
        return in_array($this, [self::ACTIVE, self::TRIALING], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::TRIALING => 'Trialing',
            self::PAST_DUE => 'Past Due',
            self::CANCELLED => 'Cancelled',
            self::PAUSED => 'Paused',
            self::EXPIRED => 'Expired',
        };
    }
}
