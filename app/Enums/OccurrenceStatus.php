<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OccurrenceStatus: string implements HasColor, HasLabel
{
    case Upcoming = 'upcoming';
    case Overdue = 'overdue';
    case Postponed = 'postponed';
    case Pending = 'pending';
    case Paid = 'paid';
    case MarkedPaid = 'marked_paid';
    case Skipped = 'skipped';
    case Current = 'current';
    case Ended = 'ended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Upcoming => 'Upcoming',
            self::Overdue => 'Overdue',
            self::Postponed => 'Postponed',
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::MarkedPaid => 'Marked paid',
            self::Skipped => 'Skipped',
            self::Current => 'Current',
            self::Ended => 'Ended',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Overdue => 'danger',
            self::Pending => 'warning',
            self::Paid, self::MarkedPaid => 'success',
            self::Current, self::Postponed => 'info',
            default => 'gray',
        };
    }
}
