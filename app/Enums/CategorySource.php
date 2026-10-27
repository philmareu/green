<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a transaction got its category. Suggestions follow Plaid's category and
 * may change; a category you picked is never replaced automatically.
 */
enum CategorySource: string implements HasLabel
{
    case Suggested = 'suggested';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Suggested => 'Suggested from Plaid’s category',
            self::Manual => 'Picked by you',
        };
    }
}
