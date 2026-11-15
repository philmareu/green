<?php

use App\Enums\Frequency;
use App\Enums\OccurrenceResolution;
use App\Models\Account;
use App\Models\Category;
use App\Models\Recurring;

/**
 * Items added on Oct 1. On Oct 21 they are next due, soonest first:
 * Vacation (Oct 5, one-time flex, current), Groceries (Oct 15, current week),
 * Rent (Oct 15, overdue), Gifts (Oct 17, its last week, still running after
 * the end date), Dining (Oct 22, this week skipped), Comcast (Oct 25),
 * Insurance (Nov 10, Oct 10 marked paid); Holiday (ended) and Laptop (paid)
 * have nothing due.
 */
function itemsDueAtDifferentTimes(Account $account): void
{
    $category = Category::factory()->create();
    $bill = fn (array $attributes): Recurring => Recurring::factory()->for($account)->create($attributes);
    $flex = fn (array $attributes): Recurring => Recurring::factory()->for($account)->flex($category)->create($attributes);
    $resolve = fn (Recurring $recurring, string $dueOn, OccurrenceResolution $resolution) => $recurring->occurrences()
        ->whereDate('due_on', $dueOn)
        ->sole()
        ->update(['resolution' => $resolution]);

    $bill(['name' => 'Rent', 'starts_on' => '2026-10-15']);
    $bill(['name' => 'Comcast', 'starts_on' => '2026-10-25']);
    $resolve($bill(['name' => 'Insurance', 'starts_on' => '2026-10-10']), '2026-10-10', OccurrenceResolution::Paid);
    $resolve($bill(['name' => 'Laptop', 'frequency' => Frequency::Once, 'starts_on' => '2026-10-12']), '2026-10-12', OccurrenceResolution::Paid);
    $flex(['name' => 'Groceries', 'starts_on' => '2026-10-01']);
    $resolve($flex(['name' => 'Dining', 'starts_on' => '2026-10-01']), '2026-10-15', OccurrenceResolution::Skipped);
    $flex(['name' => 'Gifts', 'starts_on' => '2026-10-03', 'ends_on' => '2026-10-18']);
    $flex(['name' => 'Holiday', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-10']);
    $flex(['name' => 'Vacation', 'frequency' => Frequency::Once, 'starts_on' => '2026-10-05']);
}

beforeEach(function () {
    $this->travelTo('2026-10-01 12:00');
    $this->account = Account::factory()->create();
});

it('orders by the next due date it shows, soonest first, ties by name and nothing due last', function () {
    itemsDueAtDifferentTimes($this->account);
    $this->travelTo('2026-10-21 12:00');

    $recurrings = Recurring::query()->orderByNextDue()->with('occurrences.transaction')->get();

    expect($recurrings->mapWithKeys(fn (Recurring $recurring): array => [$recurring->name => $recurring->nextOccurrence()?->due_on->toDateString()])->all())
        ->toBe([
            'Vacation' => '2026-10-05',
            'Groceries' => '2026-10-15',
            'Rent' => '2026-10-15',
            'Gifts' => '2026-10-17',
            'Dining' => '2026-10-22',
            'Comcast' => '2026-10-25',
            'Insurance' => '2026-11-10',
            'Holiday' => null,
            'Laptop' => null,
        ]);
});

it('keeps a month-end flex spend on its current period until the next one starts', function () {
    Recurring::factory()->for($this->account)->flex(Category::factory()->create())
        ->create(['name' => 'Spending', 'frequency' => Frequency::Monthly, 'starts_on' => '2026-08-31']);
    Recurring::factory()->for($this->account)->create(['name' => 'Rent', 'starts_on' => '2026-10-30']);
    $this->travelTo('2026-10-30 12:00');

    $recurrings = Recurring::query()->orderByNextDue()->with('occurrences.transaction')->get();

    expect($recurrings->mapWithKeys(fn (Recurring $recurring): array => [$recurring->name => $recurring->nextOccurrence()?->due_on->toDateString()])->all())
        ->toBe(['Spending' => '2026-09-30', 'Rent' => '2026-10-30']);
});

it('keeps items with nothing due last when ordered latest first', function () {
    itemsDueAtDifferentTimes($this->account);
    $this->travelTo('2026-10-21 12:00');

    $names = Recurring::query()->orderByNextDue('desc')->pluck('name')->all();

    expect($names)->toBe(['Insurance', 'Comcast', 'Dining', 'Gifts', 'Groceries', 'Rent', 'Vacation', 'Holiday', 'Laptop']);
});

it('orders a postponed bill by its new date, or by its next due date once the new date is later', function () {
    $bill = fn (array $attributes): Recurring => Recurring::factory()->for($this->account)->create($attributes);
    $postpone = fn (Recurring $recurring, string $dueOn, string $to) => $recurring->occurrences()
        ->whereDate('due_on', $dueOn)
        ->sole()
        ->update(['postponed_to' => $to]);
    $postpone($bill(['name' => 'Rent', 'starts_on' => '2026-10-15']), '2026-10-15', '2026-11-20');
    $postpone($bill(['name' => 'Phone', 'starts_on' => '2026-10-12']), '2026-10-12', '2026-10-28');
    $bill(['name' => 'Comcast', 'starts_on' => '2026-10-25']);
    $this->travelTo('2026-10-21 12:00');

    $recurrings = Recurring::query()->orderByNextDue()->with('occurrences.transaction')->get();

    expect($recurrings->mapWithKeys(fn (Recurring $recurring): array => [$recurring->name => $recurring->nextOccurrence()->expectedOn()->toDateString()])->all())
        ->toBe([
            'Comcast' => '2026-10-25',
            'Phone' => '2026-10-28',
            'Rent' => '2026-11-15',
        ]);
});

it('spreads quarterly and yearly bills over the months between due dates', function () {
    $this->travelTo('2026-09-01 12:00');
    $water = Recurring::factory()->for($this->account)->create(['name' => 'Water', 'amount' => 300, 'frequency' => Frequency::EveryThreeMonths, 'starts_on' => '2026-09-20']);
    $this->travelTo('2026-10-01 12:00');
    $insurance = Recurring::factory()->for($this->account)->create(['name' => 'Insurance', 'amount' => 1200, 'frequency' => Frequency::Yearly, 'starts_on' => '2027-01-01']);
    $tax = Recurring::factory()->for($this->account)->create(['name' => 'Tax', 'amount' => 300, 'frequency' => Frequency::EveryThreeMonths, 'starts_on' => '2026-11-15']);
    $rent = Recurring::factory()->for($this->account)->create(['name' => 'Rent', 'amount' => 1200, 'frequency' => Frequency::Monthly, 'starts_on' => '2026-10-15']);
    $dividend = Recurring::factory()->for($this->account)->income()->create(['amount' => 1200, 'frequency' => Frequency::Yearly, 'starts_on' => '2027-01-01']);
    $setAside = fn (Recurring $recurring): array => [$recurring->monthlySetAside(), $recurring->fresh()->setAsideByNow()];

    expect($setAside($insurance))->toBe([100.0, 897.53])
        ->and($setAside($tax))->toBe([100.0, 153.26])
        ->and($setAside($water))->toBe([100.0, 300.0])
        ->and($setAside($rent))->toBe([null, null])
        ->and($setAside($dividend))->toBe([null, null]);

    $water->occurrences()->whereDate('due_on', '2026-09-20')->sole()->update(['resolution' => OccurrenceResolution::Paid]);

    expect($water->fresh()->setAsideByNow())->toBe(36.26);
});
