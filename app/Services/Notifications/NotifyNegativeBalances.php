<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\Account;
use App\Models\PlaidItem;
use App\Models\User;
use App\Notifications\AccountWentNegative;

/**
 * Alerts a user when a visible cash account drops below zero. Each dip alerts
 * once: `accounts.negative_notified_at` is set by the alert and cleared when the
 * balance recovers.
 */
class NotifyNegativeBalances
{
    public function forItem(PlaidItem $item): void
    {
        $item->accounts()->cash()->visible()->get()
            ->each(fn (Account $account) => $this->check($account, $item->user));
    }

    protected function check(Account $account, User $user): void
    {
        $balance = $account->spendableBalance();

        if ($balance === null) {
            return;
        }

        if ($balance >= 0) {
            if ($account->negative_notified_at !== null) {
                $account->forceFill(['negative_notified_at' => null])->save();
            }

            return;
        }

        if ($account->negative_notified_at !== null || $user->notificationChannels(NotificationType::NegativeBalance) === []) {
            return;
        }

        // Set first: if mail is down, a repeat in the bell every hour is worse.
        $account->forceFill(['negative_notified_at' => now()])->save();

        $user->notify(new AccountWentNegative($account, $balance));
    }
}
