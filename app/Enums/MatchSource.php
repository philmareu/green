<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a transaction got linked to a bill or income due date.
 */
enum MatchSource: string implements HasLabel
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Auto => 'Matched automatically',
            self::Manual => 'Linked by you',
        };
    }
}
