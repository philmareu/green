<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What started a logged Plaid operation. Scheduled covers every sync queued
 * by `plaid:sync`, which the scheduler runs hourly.
 */
enum PlaidLogTrigger: string implements HasLabel
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Link = 'link';
    case Reconnect = 'reconnect';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Manual => 'Manual',
            self::Link => 'After linking',
            self::Reconnect => 'After reconnecting',
        };
    }
}
