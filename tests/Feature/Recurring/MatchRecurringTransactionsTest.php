<?php

use App\Enums\MatchSource;
use App\Enums\OccurrenceStatus;
use App\Models\Account;
use App\Models\Recurring;
use App\Models\Transaction;
use App\Services\Recurring\MatchRecurringTransactions;

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00');
    $this->account = Account::factory()->create();
});

function payment(Account $account, string $date, float $amount, string $name, ?string $merchant = null): Transaction
{
    return Transaction::factory()->for($account)->create([
        'date' => $date,
        'authorized_date' => $date,
        'amount' => $amount,
        'name' => $name,
        'merchant_name' => $merchant,
    ]);
}

function bill(Account $account, array $attributes = []): Recurring
{
    return Recurring::factory()->for($account)->create(['starts_on' => '2026-10-15', ...$attributes]);
}

function matchAccount(Account $account): int
{
    return app(MatchRecurringTransactions::class)->forAccounts([$account->id]);
}

function linkedTo(Recurring $recurring): array
{
    return $recurring->occurrences()->whereNotNull('transaction_id')->with('transaction')->get()
        ->mapWithKeys(fn ($occurrence) => [$occurrence->due_on->toDateString() => $occurrence->transaction->name])
        ->all();
}

it('links the closest payment whose description contains the payee, despite a small price change', function () {
    $comcast = bill($this->account);
    payment($this->account, '2026-10-12', 89.00, 'COMCAST CABLE 1111');
    $closest = payment($this->account, '2026-10-16', 91.20, 'COMCAST CABLE 8823');

    expect(matchAccount($this->account))->toBe(1);

    $occurrence = $comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole();
    expect($occurrence)
        ->transaction_id->toBe($closest->id)
        ->matched_by->toBe(MatchSource::Auto)
        ->and($occurrence->status())->toBe(OccurrenceStatus::Paid);
});

it('never links a payment on amount alone', function () {
    $spotify = bill($this->account, ['name' => 'Spotify', 'payee' => 'spotify', 'amount' => 11, 'starts_on' => '2026-10-20']);
    payment($this->account, '2026-10-20', 11.00, 'SHELL OIL 57442', 'Shell');

    matchAccount($this->account);
    expect(linkedTo($spotify))->toBeEmpty();

    payment($this->account, '2026-10-21', 11.50, 'SPOTIFY USA P2F1A');
    matchAccount($this->account);
    expect(linkedTo($spotify))->toBe(['2026-10-20' => 'SPOTIFY USA P2F1A']);
});

it('only links amounts within the tolerance, unless any amount is allowed', function () {
    $comcast = bill($this->account);
    payment($this->account, '2026-10-15', 120.00, 'COMCAST CABLE 8823');

    matchAccount($this->account);
    expect(linkedTo($comcast))->toBeEmpty();

    $comcast->update(['amount_tolerance' => null]);
    expect(linkedTo($comcast))->toBe(['2026-10-15' => 'COMCAST CABLE 8823']);
});

it('links income only to money coming in', function () {
    $retainer = Recurring::factory()->for($this->account)->income()->create(['starts_on' => '2026-10-20']);
    payment($this->account, '2026-10-20', 2500, 'ACME SUPPLIES');
    payment($this->account, '2026-10-21', -2500, 'ACME CORP PAYMENT');

    matchAccount($this->account);

    expect(linkedTo($retainer))->toBe(['2026-10-20' => 'ACME CORP PAYMENT']);
});

it('never links a transaction again after you unlink it', function () {
    $comcast = bill($this->account);
    payment($this->account, '2026-10-16', 89.00, 'COMCAST CABLE 8823');
    matchAccount($this->account);

    $comcast->occurrences()->whereNotNull('transaction_id')->sole()->unlink();
    matchAccount($this->account);
    $comcast->update(['payee' => 'comcast cable']);

    expect(linkedTo($comcast))->toBeEmpty();
});

it('finds earlier payments when added, but drops older unpaid due dates', function () {
    payment($this->account, '2026-08-14', 89.00, 'COMCAST CABLE 8823');

    $comcast = bill($this->account, ['starts_on' => '2026-06-15']);

    expect(linkedTo($comcast))->toBe(['2026-08-15' => 'COMCAST CABLE 8823'])
        ->and($comcast->occurrences()->whereDate('due_on', '<', '2026-10-15')->pluck('due_on')->map->toDateString()->all())->toBe(['2026-08-15'])
        ->and($comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole()->status())->toBe(OccurrenceStatus::Overdue);
});

it('keeps earlier links when the price changes, and releases them when the payee does', function () {
    payment($this->account, '2026-08-14', 89.00, 'COMCAST CABLE 8823');
    $comcast = bill($this->account, ['starts_on' => '2026-08-15']);
    $manual = payment($this->account, '2026-10-15', 89.00, 'ONLINE PAYMENT 4471');
    $comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole()->link($manual, MatchSource::Manual);

    $comcast->update(['amount' => 120]);
    expect(linkedTo($comcast))->toBe(['2026-08-15' => 'COMCAST CABLE 8823', '2026-10-15' => 'ONLINE PAYMENT 4471']);

    $comcast->update(['payee' => 'xfinity']);
    expect(linkedTo($comcast))->toBe(['2026-10-15' => 'ONLINE PAYMENT 4471']);
});

it('links a postponed bill to a payment near its new date, outside the old date’s window', function () {
    $comcast = bill($this->account);
    $comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole()->update(['postponed_to' => '2026-10-30']);
    $this->travelTo('2026-10-30 12:00');
    payment($this->account, '2026-10-29', 89.00, 'COMCAST CABLE 8823');

    matchAccount($this->account);

    expect(linkedTo($comcast))->toBe(['2026-10-15' => 'COMCAST CABLE 8823']);
});
