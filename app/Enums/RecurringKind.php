<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Bills and income are paid by one transaction each time they're due. A flex
 * spend is a budget per period, used up by every transaction in its category.
 */
enum RecurringKind: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Bill = 'bill';
    case Flex = 'flex';
    case Income = 'income';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bill => 'Bill',
            self::Flex => 'Flex spend',
            self::Income => 'Income',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bill => 'warning',
            self::Flex => 'info',
            self::Income => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Bill => Heroicon::OutlinedDocumentText,
            self::Flex => Heroicon::OutlinedShoppingCart,
            self::Income => Heroicon::OutlinedBanknotes,
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Bill => 'Paid by one transaction each time',
            self::Flex => 'A budget per period, like groceries or gas',
            self::Income => 'Money you expect to receive',
        };
    }

    public function isOutflow(): bool
    {
        return $this !== self::Income;
    }
}
