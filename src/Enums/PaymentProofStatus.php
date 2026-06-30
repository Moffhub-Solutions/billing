<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

enum PaymentProofStatus: string
{
    case PENDING = 'pending';
    case VERIFIED = 'verified';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending verification',
            self::VERIFIED => 'Verified',
            self::REJECTED => 'Rejected',
        };
    }
}
