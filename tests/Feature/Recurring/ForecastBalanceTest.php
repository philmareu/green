<?php

use App\Enums\MatchSource;
use App\Enums\OccurrenceResolution;
use App\Models\Account;
use App\Models\Category;
use App\Models\Recurring;
use App\Models\Transaction;
use App\Services\Recurring\ForecastBalance;
use App\Services\Recurring\ForecastEntry;

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00');
    $this->account = Account::factory()->create(['current_balance' => 1100, 'available_balance' => 1000]);
});

function forecast(Account $account, string $until)
{
    return app(ForecastBalance::class)->handle($account, now()->parse($until));
}

/**
 * @return list<array{0: string, 1: string, 2: float, 3: float}> date, name, amount, balance
 */
function register($forecast): array
{
    return array_map(fn (ForecastEntry $entry) => [$entry->date->toDateString(), $entry->name, $entry->amount, $entry->balance], $forecast->entries);
}

it('starts from the available balance, then subtracts bills and adds income in date order', function () {
    Recurring::factory()->for($this->account)->create(['name' => 'Comcast', 'amount' => 89, 'starts_on' => '2026-10-25']);
    Recurring::factory()->for($this->account)->income()->create(['name' => 'Retainer', 'amount' => 2500, 'starts_on' => '2026-11-01']);

    $forecast = forecast($this->account, '2026-11-30');

    expect(register($forecast))->toBe([
        ['2026-10-25', 'Comcast', -89.0, 911.0],
        ['2026-11-01', 'Retainer', 2500.0, 3411.0],
        ['2026-11-25', 'Comcast', -89.0, 3322.0],
    ])
        ->and($forecast->starting)->toBe(1000.0)
        ->and($forecast->ending())->toBe(3322.0)
        ->and($forecast->lowest()['balance'])->toBe(911.0)
        ->and($forecast->lowest()['date']->toDateString())->toBe('2026-10-25');
});

it('counts an overdue bill today, and late income only until its match window passes', function () {
    $this->travelTo('2026-10-01');
    Recurring::factory()->for($this->account)->create(['name' => 'Rent', 'amount' => 900, 'starts_on' => '2026-10-15']);
    Recurring::factory()->for($this->account)->income()->create(['name' => 'Long late', 'starts_on' => '2026-10-05']);
    Recurring::factory()->for($this->account)->income()->create(['name' => 'Just late', 'amount' => 300, 'starts_on' => '2026-10-18']);
    $this->travelTo('2026-10-21');

    $forecast = forecast($this->account, '2026-10-31');

    expect(register($forecast))->toBe([
        ['2026-10-21', 'Rent', -900.0, 100.0],
        ['2026-10-21', 'Just late', 300.0, 400.0],
    ])
        ->and($forecast->entries[0]->note)->toBe('Overdue since Oct 15')
        ->and($forecast->entries[1]->note)->toBe('Expected Oct 18');
});

it('leaves out bills that are paid, pending or skipped', function () {
    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-25']);
    $pending = Transaction::factory()->for($this->account)->pending()->create();
    $comcast->occurrences()->whereDate('due_on', '2026-10-25')->sole()->link($pending, MatchSource::Manual);
    $comcast->occurrences()->whereDate('due_on', '2026-11-25')->sole()->update(['resolution' => OccurrenceResolution::Skipped]);

    $forecast = forecast($this->account, '2026-11-30');

    expect($forecast->entries)->toBeEmpty()
        ->and($forecast->ending())->toBe(1000.0);
});

it('reserves what is left of a flex budget this period, then the full budget for later periods', function () {
    $groceries = Category::factory()->create(['name' => 'Groceries']);
    $produce = Category::factory()->childOf($groceries)->create();
    Recurring::factory()->for($this->account)->flex($groceries)->create(['amount' => 150, 'starts_on' => '2026-10-19']);
    $spend = fn (float $amount, ?Category $category, string $date = '2026-10-20') => Transaction::factory()->for($this->account)
        ->create(['amount' => $amount, 'category_id' => $category?->id, 'date' => $date, 'authorized_date' => $date]);
    $spend(50.00, $groceries);
    $spend(12.40, $produce);
    $spend(-10.00, $groceries);
    $spend(30.00, null);
    $spend(40.00, $groceries, '2026-10-18');

    expect(register(forecast($this->account, '2026-11-01')))->toBe([
        ['2026-10-21', 'Groceries', -97.6, 902.4],
        ['2026-10-26', 'Groceries', -150.0, 752.4],
    ]);
});

it('counts a postponed bill on its new date, and as overdue once that date passes', function () {
    $this->travelTo('2026-10-01');
    $comcast = Recurring::factory()->for($this->account)->create(['name' => 'Comcast', 'amount' => 89, 'starts_on' => '2026-10-25']);
    $comcast->occurrences()->whereDate('due_on', '2026-10-25')->sole()->update(['postponed_to' => '2026-11-03']);
    $rent = Recurring::factory()->for($this->account)->create(['name' => 'Rent', 'amount' => 900, 'starts_on' => '2026-10-10']);
    $rent->occurrences()->whereDate('due_on', '2026-10-10')->sole()->update(['postponed_to' => '2026-10-18']);
    $this->travelTo('2026-10-21');

    $forecast = forecast($this->account, '2026-11-30');

    expect(register($forecast))->toBe([
        ['2026-10-21', 'Rent', -900.0, 100.0],
        ['2026-11-03', 'Comcast', -89.0, 11.0],
        ['2026-11-10', 'Rent', -900.0, -889.0],
        ['2026-11-25', 'Comcast', -89.0, -978.0],
    ])
        ->and($forecast->entries[0]->note)->toBe('Overdue since Oct 18')
        ->and($forecast->entries[1]->note)->toBe('Postponed from Oct 25')
        ->and(register(forecast($this->account, '2026-10-31')))->toBe([
            ['2026-10-21', 'Rent', -900.0, 100.0],
        ]);
});

it('reserves what rolled over into this period, and takes overspending out of the next', function () {
    $this->travelTo('2026-10-01');
    $groceries = Category::factory()->create(['name' => 'Groceries']);
    $dining = Category::factory()->create(['name' => 'Dining']);
    Recurring::factory()->for($this->account)->flex($groceries)->create(['amount' => 100, 'starts_on' => '2026-10-12', 'rolls_over' => true]);
    Recurring::factory()->for($this->account)->flex($dining)->create(['amount' => 50, 'starts_on' => '2026-10-12', 'rolls_over' => true]);
    $spend = fn (Category $category, float $amount, string $date) => Transaction::factory()->for($this->account)
        ->create(['amount' => $amount, 'category_id' => $category->id, 'date' => $date, 'authorized_date' => $date]);
    $spend($groceries, 40.00, '2026-10-13');
    $spend($groceries, 200.00, '2026-10-20');
    $spend($dining, 20.00, '2026-10-13');
    $spend($dining, 10.00, '2026-10-20');
    $this->travelTo('2026-10-21');

    $forecast = forecast($this->account, '2026-11-08');

    expect(register($forecast))->toBe([
        ['2026-10-21', 'Dining', -70.0, 930.0],
        ['2026-10-26', 'Groceries', -60.0, 870.0],
        ['2026-10-26', 'Dining', -50.0, 820.0],
        ['2026-11-02', 'Groceries', -100.0, 720.0],
        ['2026-11-02', 'Dining', -50.0, 670.0],
    ])
        ->and($forecast->entries[0]->note)->toBe('$10.00 of $80.00 spent so far')
        ->and($forecast->entries[1]->note)->toBe('Budget until Nov 1, less $40.00 overspent');
});
