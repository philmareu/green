<?php

use App\Enums\Frequency;
use App\Enums\MatchSource;
use App\Enums\OccurrenceResolution;
use App\Enums\OccurrenceStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Occurrence;
use App\Models\Recurring;
use App\Models\Transaction;

it('finds as overdue exactly the due dates whose status is overdue', function () {
    $this->travelTo('2026-10-01 12:00');
    $account = Account::factory()->create();
    $weekly = Recurring::factory()->for($account)->create(['name' => 'Cleaner', 'frequency' => Frequency::Weekly, 'starts_on' => '2026-09-30']);
    $dueOn = fn (string $date): Occurrence => $weekly->occurrences()->whereDate('due_on', $date)->sole();
    $dueOn('2026-09-30')->link(Transaction::factory()->for($account)->create(), MatchSource::Manual);
    $dueOn('2026-10-07')->link(Transaction::factory()->for($account)->pending()->create(), MatchSource::Manual);
    $dueOn('2026-10-14')->update(['resolution' => OccurrenceResolution::Skipped]);
    Recurring::factory()->for($account)->create(['name' => 'Insurance', 'starts_on' => '2026-10-10'])
        ->occurrences()->whereDate('due_on', '2026-10-10')->sole()->update(['resolution' => OccurrenceResolution::Paid]);
    Recurring::factory()->for($account)->create(['name' => 'Rent', 'starts_on' => '2026-10-15']);
    Recurring::factory()->for($account)->create(['name' => 'Phone', 'starts_on' => '2026-10-12'])
        ->occurrences()->whereDate('due_on', '2026-10-12')->sole()->update(['postponed_to' => '2026-10-25']);
    Recurring::factory()->for($account)->create(['name' => 'Gym', 'starts_on' => '2026-10-05'])
        ->occurrences()->whereDate('due_on', '2026-10-05')->sole()->update(['postponed_to' => '2026-10-18']);
    Recurring::factory()->for($account)->income()->create(['name' => 'Retainer', 'starts_on' => '2026-10-20']);
    Recurring::factory()->for($account)->flex(Category::factory()->create())->create(['name' => 'Groceries', 'starts_on' => '2026-10-01']);
    $this->travelTo('2026-10-21 12:00');

    $occurrences = Occurrence::query()->with('recurring', 'transaction')->get();
    $overdue = Occurrence::query()->overdue()->with('recurring')->get();

    expect($overdue->map(fn (Occurrence $occurrence): string => $occurrence->recurring->name.' '.$occurrence->due_on->toDateString())->all())
        ->toEqualCanonicalizing(['Rent 2026-10-15', 'Retainer 2026-10-20', 'Gym 2026-10-05'])
        ->and($occurrences->map->status()->unique()->all())->toContain(...OccurrenceStatus::cases())
        ->and($occurrences->filter(fn (Occurrence $occurrence): bool => $occurrence->status() === OccurrenceStatus::Overdue)->values()->modelKeys())
        ->toEqualCanonicalizing($overdue->modelKeys());
});

it('rolls unspent money and overspending into later periods, leaving out skipped ones', function (bool $rollsOver, array $budgets) {
    $this->travelTo('2026-10-01 12:00');
    $account = Account::factory()->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);
    $weekly = Recurring::factory()->for($account)->flex($groceries)->create(['amount' => 100, 'starts_on' => '2026-10-05', 'rolls_over' => $rollsOver]);
    $spend = fn (float $amount, string $date) => Transaction::factory()->for($account)
        ->create(['amount' => $amount, 'category_id' => $groceries->id, 'date' => $date, 'authorized_date' => $date]);
    $spend(70.00, '2026-10-06');
    $spend(500.00, '2026-10-13');
    $spend(160.00, '2026-10-20');
    $weekly->occurrences()->whereDate('due_on', '2026-10-12')->sole()->update(['resolution' => OccurrenceResolution::Skipped]);
    $this->travelTo('2026-10-27 12:00');

    expect($weekly->occurrences()->whereDate('due_on', '<=', '2026-10-26')->get()
        ->mapWithKeys(fn (Occurrence $occurrence): array => [$occurrence->due_on->toDateString() => $occurrence->budget()])
        ->all())->toBe($budgets);
})->with([
    'rolls over' => [true, ['2026-10-05' => 100.0, '2026-10-12' => 130.0, '2026-10-19' => 130.0, '2026-10-26' => 70.0]],
    'resets each period' => [false, ['2026-10-05' => 100.0, '2026-10-12' => 100.0, '2026-10-19' => 100.0, '2026-10-26' => 100.0]],
]);
