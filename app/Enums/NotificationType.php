<?php

namespace App\Enums;

use App\Models\Recurring;

/**
 * Each case is one setting on the Notifications page. A user's choice is the
 * list of channels it goes to, and an empty list turns it off.
 */
enum NotificationType: string
{
    case NegativeBalance = 'negative_balance';
    case Reminder = 'reminder';
    case BudgetAlert = 'budget_alert';

    public function label(): string
    {
        return match ($this) {
            self::NegativeBalance => 'Negative balance',
            self::Reminder => 'Reminders',
            self::BudgetAlert => 'Budget alerts',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NegativeBalance => 'When a cash account drops below zero, usually after a pending transaction. You get one alert each time it goes negative.',
            self::BudgetAlert => 'When spending on a flex spend reaches '.Recurring::BUDGET_WARNING_PERCENT.'% of its budget for the period, and again if it goes over. Each alert comes once per period.',
            self::Reminder => 'The reminders you set on bills and income, sent in the morning the set number of days before the due date. Nothing is sent for a due date that is already paid or skipped.',
        };
    }

    /**
     * The channels used until the user saves their own choice. Email stays
     * opt-in, because not every install has a mail server.
     *
     * @return list<string>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::NegativeBalance, self::Reminder, self::BudgetAlert => ['database'],
        };
    }
}
