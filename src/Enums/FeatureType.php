<?php

declare(strict_types=1);

namespace Moffhub\Billing\Enums;

enum FeatureType: string
{
    case BOOLEAN = 'boolean';
    case METERED = 'metered';
    case CONSUMABLE = 'consumable';

    public function isTrackable(): bool
    {
        return in_array($this, [self::METERED, self::CONSUMABLE], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::BOOLEAN => 'Boolean (on/off)',
            self::METERED => 'Metered (resets each period)',
            self::CONSUMABLE => 'Consumable (one-time allowance)',
        };
    }
}
