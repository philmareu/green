<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Enums\RecurringKind;
use App\Models\Reminder;
use App\Notifications\RecurringReminder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Sends the reminders whose due date is within reach. Runs every morning, and
 * catches up on a missed morning as long as the due date hasn't passed. A
 * failure is reported without stopping the other reminders.
 */
class SendReminders
{
    /**
     * @return int How many reminders were sent.
     */
    public function handle(): int
    {
        $today = today()->toImmutable();

        return Reminder::query()
            ->whereHas('recurring', fn (Builder $recurring) => $recurring->where('kind', '!=', RecurringKind::Flex))
            ->with('recurring.user', 'recurring.account')
            ->get()
            ->filter(function (Reminder $reminder) use ($today): bool {
                try {
                    return $this->send($reminder, $today);
                } catch (Throwable $exception) {
                    report($exception);

                    return false;
                }
            })
            ->count();
    }

    protected function send(Reminder $reminder, CarbonImmutable $today): bool
    {
        $recurring = $reminder->recurring;
        $user = $recurring->user;

        if ($user->notificationChannels(NotificationType::Reminder) === []) {
            return false;
        }

        $recurring->syncOccurrences();
        $occurrence = $reminder->dueOccurrence($today);

        if ($occurrence === null) {
            return false;
        }

        // Record it first: if mail is down, a repeat every morning is worse.
        if ($reminder->repeats) {
            $reminder->update(['reminded_through' => $occurrence->expectedOn()]);
        } else {
            $reminder->delete();
        }

        $user->notify(new RecurringReminder($recurring, $occurrence, $reminder->note));

        return true;
    }
}
