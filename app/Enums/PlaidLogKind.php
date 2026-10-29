<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What Green asked Plaid to do. Live balance is the one Plaid bills per call.
 */
enum PlaidLogKind: string implements HasLabel
{
    case Sync = 'sync';
    case LiveBalance = 'live_balance';
    case Link = 'link';
    case Remove = 'remove';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sync => 'Sync',
            self::LiveBalance => 'Live balance',
            self::Link => 'Link',
            self::Remove => 'Remove',
        };
    }
}
