<?php

use App\Enums\MatchSource;
use App\Enums\OccurrenceResolution;
use App\Models\Account;
use App\Models\Category;
use App\Models\Recurring;
use App\Models\Reminder;
use App\Models\Transaction;
use App\Notifications\RecurringReminder;
use App\Services\Notifications\SendReminders;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo('2026-10-01 08:00');
});

/**
 * Comcast, $89 a month, due on the 15th from Oct 15.
 */
function comcastBill(array $attributes = []): Recurring
{
    return Recurring::factory()->for(Account::factory()->create())->create(['starts_on' => '2026-10-15', ...$attributes]);
}

function sendReminders(string $on): int
{
    test()->travelTo("{$on} 08:00");

    return app(SendReminders::class)->handle();
}

it('sends a one-off reminder the set number of days before, then removes it', function () {
    Notification::fake();
    $comcast = comcastBill();
    $reminder = Reminder::factory()->for($comcast)->create(['days_before' => 3, 'note' => 'Cancel before it renews']);

    expect(sendReminders('2026-10-11'))->toBe(0);
    Notification::assertNothingSent();

    expect(sendReminders('2026-10-12'))->toBe(1);
    Notification::assertSentTo($comcast->user, RecurringReminder::class, fn (RecurringReminder $notification): bool => $notification->occurrence->due_on->toDateString() === '2026-10-15'
        && $notification->note === 'Cancel before it renews');
    expect($reminder->fresh())->toBeNull();
});

it('repeats once before every due date', function () {
    Notification::fake();
    $comcast = comcastBill();
    Reminder::factory()->for($comcast)->repeating()->create(['days_before' => 3]);

    $sent = collect(['2026-10-12', '2026-10-13', '2026-10-14', '2026-11-11', '2026-11-12', '2026-11-13'])
        ->mapWithKeys(fn (string $day): array => [$day => sendReminders($day)])
        ->all();

    expect($sent)->toBe([
        '2026-10-12' => 1,
        '2026-10-13' => 0,
        '2026-10-14' => 0,
        '2026-11-11' => 0,
        '2026-11-12' => 1,
        '2026-11-13' => 0,
    ]);
    Notification::assertSentToTimes($comcast->user, RecurringReminder::class, 2);
});

it('catches up on a missed morning until the due date passes', function () {
    Notification::fake();
    $comcast = comcastBill();
    Reminder::factory()->for($comcast)->create(['days_before' => 3]);

    expect(sendReminders('2026-10-16'))->toBe(0)
        ->and(sendReminders('2026-11-12'))->toBe(1);

    Notification::assertSentTo($comcast->user, RecurringReminder::class, fn (RecurringReminder $notification): bool => $notification->occurrence->due_on->toDateString() === '2026-11-15');
});

it('sends nothing for a due date that is already settled, and reminds about the next one', function (Closure $settle) {
    Notification::fake();
    $comcast = comcastBill();
    Reminder::factory()->for($comcast)->create(['days_before' => 3]);
    $settle($comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole());

    expect(sendReminders('2026-10-12'))->toBe(0)
        ->and(sendReminders('2026-11-12'))->toBe(1);
})->with([
    'paid by a transaction' => [fn ($occurrence) => $occurrence->link(Transaction::factory()->for($occurrence->recurring->account)->pending()->create(), MatchSource::Manual)],
    'marked paid' => [fn ($occurrence) => $occurrence->update(['resolution' => OccurrenceResolution::Paid])],
    'skipped' => [fn ($occurrence) => $occurrence->update(['resolution' => OccurrenceResolution::Skipped])],
]);

it('times a postponed due date from its new date', function () {
    Notification::fake();
    $comcast = comcastBill();
    Reminder::factory()->for($comcast)->create(['days_before' => 3]);
    $comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole()->update(['postponed_to' => '2026-10-25']);

    expect(sendReminders('2026-10-12'))->toBe(0)
        ->and(sendReminders('2026-10-22'))->toBe(1);
});

it('leaves out flex spends', function () {
    Notification::fake();
    $groceries = Recurring::factory()->for(Account::factory()->create())->flex(Category::factory()->create())->create(['starts_on' => '2026-10-15']);
    Reminder::factory()->for($groceries)->create(['days_before' => 3]);

    expect(sendReminders('2026-10-12'))->toBe(0);
});

it('keeps a reminder while its owner has reminders turned off, and sends it once they turn them on', function () {
    Notification::fake();
    $comcast = comcastBill();
    $user = $comcast->user;
    $user->forceFill(['notification_preferences' => ['reminder' => []]])->save();
    $reminder = Reminder::factory()->for($comcast)->create(['days_before' => 3]);

    expect(sendReminders('2026-10-12'))->toBe(0)
        ->and($reminder->fresh())->not->toBeNull();

    $user->forceFill(['notification_preferences' => ['reminder' => ['database']]])->save();

    expect(sendReminders('2026-10-13'))->toBe(1);
});

it('puts the note, due date and amount in the bell, and leaves the amount out of the email', function () {
    $comcast = comcastBill(['name' => 'Netflix', 'amount' => 15.49]);
    Reminder::factory()->for($comcast)->create(['days_before' => 3, 'note' => 'Cancel before it renews']);

    sendReminders('2026-10-12');

    expect($comcast->user->notifications()->sole()->data)
        ->title->toBe('Cancel before it renews')
        ->body->toBe('Netflix is due Thu, Oct 15 · $15.49');

    $mail = (new RecurringReminder($comcast, $comcast->occurrences()->first(), 'Cancel before it renews'))->toMail($comcast->user);

    expect((string) $mail->render())
        ->toContain('Cancel before it renews')
        ->toContain('Netflix is due Thu, Oct 15')
        ->not->toContain('15.49');
});

it('shows markup in a name or note as text, in the bell and in the email', function () {
    $name = '<a href="https://evil.example/pay">Netflix</a>';
    $note = '<img src="https://evil.example/pixel.png"> [Sign in](https://evil.example/login)';
    $bill = comcastBill(['name' => $name]);
    Reminder::factory()->for($bill)->create(['days_before' => 3, 'note' => $note]);

    sendReminders('2026-10-12');

    expect($bill->user->notifications()->sole()->data)
        ->title->toBe(e($note))
        ->body->toStartWith(e($name));

    $mail = (string) (new RecurringReminder($bill, $bill->occurrences()->first(), $note))->toMail($bill->user)->render();

    expect($mail)
        ->toContain('Netflix')
        ->not->toContain('<img src="https://evil.example')
        ->not->toContain('<a href="https://evil.example');
});

it('names the due date in the title when there is no note', function () {
    $retainer = Recurring::factory()->for(Account::factory()->create())->income()->create(['starts_on' => '2026-10-15']);
    Reminder::factory()->for($retainer)->create(['days_before' => 0]);

    sendReminders('2026-10-15');

    expect($retainer->user->notifications()->sole()->data)
        ->title->toBe('Retainer is expected Thu, Oct 15')
        ->body->toBe('$2,500.00');
});

it('reports a failed reminder and still sends the others', function () {
    Exceptions::fake();
    $broken = comcastBill();
    $working = comcastBill(['name' => 'Netflix']);
    Reminder::factory()->for($broken)->create(['days_before' => 3]);
    Reminder::factory()->for($working)->create(['days_before' => 3]);
    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($broken): void {
        if ($event->notifiable->is($broken->user)) {
            throw new RuntimeException('Mail is down');
        }
    });

    expect(sendReminders('2026-10-12'))->toBe(1)
        ->and($working->user->notifications()->count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('sends the due reminders from the command', function () {
    Notification::fake();
    Reminder::factory()->for(comcastBill())->create(['days_before' => 3]);
    $this->travelTo('2026-10-12 08:00');

    $this->artisan('reminders:send')
        ->expectsOutputToContain('Sent 1 reminder(s).')
        ->assertSuccessful();
});
