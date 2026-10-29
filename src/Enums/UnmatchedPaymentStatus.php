<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

enum UnmatchedPaymentStatus: string
{
    case OPEN = 'open';
    case ALLOCATED = 'allocated';
    case DISMISSED = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Needs allocating',
            self::ALLOCATED => 'Allocated',
            self::DISMISSED => 'Dismissed',
        };
    }
}
