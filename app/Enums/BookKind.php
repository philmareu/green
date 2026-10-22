<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum BookKind: string implements HasColor, HasIcon, HasLabel
{
    case Business = 'business';
    case Personal = 'personal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Business => 'Business',
            self::Personal => 'Personal',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Business => 'info',
            self::Personal => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Business => Heroicon::OutlinedBuildingOffice,
            self::Personal => Heroicon::OutlinedHome,
        };
    }
}
