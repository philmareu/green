<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a transaction got its category. Suggestions follow Plaid's category,
 * payee ones a category's payees, and recurring ones the bill or income the
 * transaction pays; all may change. A category you picked is never replaced
 * automatically.
 */
enum CategorySource: string implements HasLabel
{
    case Suggested = 'suggested';
    case Payee = 'payee';
    case Recurring = 'recurring';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Suggested => 'Suggested from Plaid’s category',
            self::Payee => 'Matched a payee on the category',
            self::Recurring => 'From its recurring bill or income',
            self::Manual => 'Picked by you',
        };
    }

    /**
     * Whether Green chose it, so it follows changes to what it came from.
     */
    public function isAutomatic(): bool
    {
        return $this !== self::Manual;
    }
}
