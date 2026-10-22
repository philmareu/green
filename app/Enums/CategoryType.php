<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether money in a category counts as income, spending, or neither. Transfers
 * (card payments, moves between your own accounts, owner draws) are left out of
 * income and spending totals.
 */
enum CategoryType: string implements HasColor, HasDescription, HasLabel
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Income => 'Income',
            self::Expense => 'Expense',
            self::Transfer => 'Transfer',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Income => 'success',
            self::Expense => 'gray',
            self::Transfer => 'info',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Income => 'Money earned',
            self::Expense => 'Money spent',
            self::Transfer => 'Moving your own money; not income or spending',
        };
    }
}
