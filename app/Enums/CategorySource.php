<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a transaction got its category. Suggestions follow Plaid's category and
 * recurring ones follow the bill or income the transaction pays; both may
 * change. A category you picked is never replaced automatically.
 */
enum CategorySource: string implements HasLabel
{
    case Suggested = 'suggested';
    case Recurring = 'recurring';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Suggested => 'Suggested from Plaid’s category',
            self::Recurring => 'From its recurring bill or income',
            self::Manual => 'Picked by you',
        };
    }
}
