<?php

namespace App\Enums;

/**
 * Each case is one setting on the Notifications page. A user's choice is the
 * list of channels it goes to, and an empty list turns it off.
 */
enum NotificationType: string
{
    case NegativeBalance = 'negative_balance';

    public function label(): string
    {
        return match ($this) {
            self::NegativeBalance => 'Negative balance',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NegativeBalance => 'When a cash account drops below zero, usually after a pending transaction. You get one alert each time it goes negative.',
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
            self::NegativeBalance => ['database'],
        };
    }
}
