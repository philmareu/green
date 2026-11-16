<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Models\Occurrence;
use App\Models\Recurring;
use App\Notifications\BudgetAlert;

/**
 * Alerts a user when a flex spend's current period gets close to its budget,
 * and again when it goes over. Each level alerts once per period, tracked by
 * `occurrences.budget_alert`; going straight over sends only the over alert.
 * Runs after each sync, once transactions are categorized.
 */
class NotifyBudgetAlerts
{
    /**
     * @param  iterable<int>  $accountIds
     */
    public function forAccounts(iterable $accountIds): void
    {
        Recurring::query()
            ->whereIn('account_id', collect($accountIds)->all())
            ->where('kind', RecurringKind::Flex)
            ->with('user', 'account', 'category', 'occurrences')
            ->get()
            ->each(fn (Recurring $recurring) => $this->check($recurring));
    }

    protected function check(Recurring $recurring): void
    {
        $period = $recurring->occurrences->first(fn (Occurrence $occurrence): bool => $occurrence->status() === OccurrenceStatus::Current);

        if ($period === null) {
            return;
        }

        $spent = $period->spent();
        $level = $period->budgetAlertLevel($spent);

        if ($level === null || $level <= ($period->budget_alert ?? 0)) {
            return;
        }

        if ($recurring->user->notificationChannels(NotificationType::BudgetAlert) === []) {
            return;
        }

        // Record it first: if mail is down, a repeat after every sync is worse.
        $period->update(['budget_alert' => $level]);

        $recurring->user->notify(new BudgetAlert($recurring, $period, $spent));
    }
}
