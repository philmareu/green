<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PlaidItemStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case LoginRequired = 'login_required';
    case Error = 'error';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Connected',
            self::LoginRequired => 'Reconnect required',
            self::Error => 'Error',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::LoginRequired => 'warning',
            self::Error => 'danger',
        };
    }
}
