<?php

use App\Enums\CategorySource;
use App\Enums\Frequency;
use App\Enums\MatchSource;
use App\Enums\OccurrenceResolution;
use App\Enums\RecurringKind;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\RecurringsRelationManager;
use App\Filament\Resources\Recurrings\Pages\CreateRecurring;
use App\Filament\Resources\Recurrings\Pages\ListRecurrings;
use App\Filament\Resources\Recurrings\Pages\ViewRecurring;
use App\Filament\Resources\Recurrings\RelationManagers\OccurrencesRelationManager;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\Recurring;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->account = Account::factory()->inBook(Book::factory()->for($this->user)->create())->create();
});

function comcastPayment(Account $account, string $date, float $amount = 89.00): Transaction
{
    return Transaction::factory()->for($account)->create([
        'date' => $date,
        'authorized_date' => $date,
        'amount' => $amount,
        'name' => 'COMCAST CABLE '.random_int(1000, 9999),
        'merchant_name' => null,
    ]);
}

it('lists only your recurring items', function () {
    $mine = Recurring::factory()->for($this->account)->create();
    $theirs = Recurring::factory()->create();

    Livewire::test(ListRecurrings::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    Livewire::test(ViewRecurring::class, ['record' => $theirs->getRouteKey()])->assertNotFound();
});

it('links a recurring item to its account, except on the account’s own page', function () {
    $comcast = Recurring::factory()->for($this->account)->create();
    $accountUrl = AccountResource::getUrl('view', ['record' => $this->account]);

    Livewire::test(ListRecurrings::class)
        ->assertTableColumnExists('account.name', linksTo($accountUrl), $comcast);

    Livewire::test(ViewRecurring::class, ['record' => $comcast->getRouteKey()])
        ->assertSchemaComponentExists('account.name', 'infolist', linksTo($accountUrl));

    Livewire::test(RecurringsRelationManager::class, ['ownerRecord' => $this->account, 'pageClass' => ViewAccount::class])
        ->assertTableColumnHidden('account.name');
});

it('leaves the account unlinked when a recurring item has none', function () {
    $orphan = Recurring::factory()->for($this->user)->create(['account_id' => null]);

    Livewire::test(ListRecurrings::class)
        ->assertTableColumnExists('account.name', linksTo(null), $orphan);

    Livewire::test(ViewRecurring::class, ['record' => $orphan->getRouteKey()])
        ->assertSchemaComponentExists('account.name', 'infolist', linksTo(null));
});

it('creates a bill on your account and links its earlier payments', function () {
    $august = comcastPayment($this->account, '2026-08-14');

    Livewire::test(CreateRecurring::class)
        ->fillForm([
            'kind' => RecurringKind::Bill->value,
            'name' => 'Comcast',
            'account_id' => $this->account->id,
            'amount' => 89,
            'frequency' => Frequency::Monthly->value,
            'starts_on' => '2026-08-15',
            'payee' => 'comcast',
            'amount_tolerance' => 10,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $comcast = Recurring::sole();
    expect($comcast->user_id)->toBe($this->user->id)
        ->and($comcast->occurrences()->whereDate('due_on', '2026-08-15')->sole()->transaction_id)->toBe($august->id);
});

it('refuses someone else’s account, and a flex spend without a category', function () {
    Livewire::test(CreateRecurring::class)
        ->fillForm(['name' => 'Rent', 'account_id' => Account::factory()->create()->id, 'amount' => 900])
        ->call('create')
        ->assertHasFormErrors(['account_id']);

    Livewire::test(CreateRecurring::class)
        ->fillForm(['kind' => RecurringKind::Flex->value, 'name' => 'Groceries', 'account_id' => $this->account->id, 'amount' => 150, 'frequency' => Frequency::Weekly->value])
        ->call('create')
        ->assertHasFormErrors(['category_id' => 'required']);

    expect(Recurring::count())->toBe(0);
});

it('unlinks a payment for good, and marks a due date paid', function () {
    $payment = comcastPayment($this->account, '2026-10-15');
    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-15']);
    $occurrence = $comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole();
    expect($occurrence->transaction_id)->toBe($payment->id);

    $dueDates = Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $comcast, 'pageClass' => ViewRecurring::class])
        ->callAction(TestAction::make('unlink')->table($occurrence));

    expect($occurrence->fresh()->transaction_id)->toBeNull()
        ->and($comcast->fresh()->dismissed_transactions)->toBe([$payment->plaid_transaction_id]);

    $dueDates->callAction(TestAction::make('markPaid')->table($occurrence));

    expect($occurrence->fresh()->resolution)->toBe(OccurrenceResolution::Paid);
});

it('makes a transaction recurring, linking it and earlier payments', function () {
    $september = comcastPayment($this->account, '2026-09-14');
    $october = comcastPayment($this->account, '2026-10-14', 91.20);

    Livewire::test(ListTransactions::class)
        ->callAction(TestAction::make('makeRecurring')->table($october))
        ->assertHasNoFormErrors();

    $comcast = Recurring::sole();
    expect($comcast)
        ->kind->toBe(RecurringKind::Bill)
        ->payee->toBe('COMCAST CABLE')
        ->amount->toEqual('91.2000')
        ->and($comcast->starts_on->toDateString())->toBe('2026-08-14')
        ->and($comcast->occurrences()->whereNotNull('transaction_id')->pluck('transaction_id')->all())->toBe([$september->id, $october->id]);
});

it('makes a categorized transaction recurring, giving its category to earlier payments', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $internet = Category::factory()->for($set)->create(['name' => 'Internet']);
    $this->account->book->update(['category_set_id' => $set->id]);
    $september = comcastPayment($this->account, '2026-09-14');
    $october = comcastPayment($this->account, '2026-10-14');
    $october->update(['category_id' => $internet->id, 'category_source' => CategorySource::Manual]);

    Livewire::test(ListTransactions::class)
        ->callAction(TestAction::make('makeRecurring')->table($october))
        ->assertHasNoFormErrors();

    expect(Recurring::sole()->category_id)->toBe($internet->id)
        ->and($september->fresh())->category_id->toBe($internet->id)->category_source->toBe(CategorySource::Recurring);
});

it('links a payment by hand even when it doesn’t match the payee', function () {
    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-15']);
    $transfer = Transaction::factory()->for($this->account)->create(['name' => 'ONLINE TRANSFER 3321', 'merchant_name' => null, 'amount' => 89, 'date' => '2026-10-16', 'authorized_date' => '2026-10-16']);
    $occurrence = $comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole();

    Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $comcast, 'pageClass' => ViewRecurring::class])
        ->callAction(TestAction::make('link')->table($occurrence), data: ['transaction_id' => $transfer->id]);

    expect($occurrence->fresh())->transaction_id->toBe($transfer->id)->matched_by->toBe(MatchSource::Manual);
});
