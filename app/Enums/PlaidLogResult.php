<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a logged Plaid operation ended. An entry is unfinished while it runs,
 * and stays that way when the process is stopped first (a job timeout).
 */
enum PlaidLogResult: string implements HasColor, HasLabel
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unfinished = 'unfinished';

    public function getLabel(): string
    {
        return match ($this) {
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Unfinished => 'Unfinished',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Unfinished => 'warning',
        };
    }
}
