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
use App\Filament\Resources\Recurrings\RecurringResource;
use App\Filament\Resources\Recurrings\RelationManagers\OccurrencesRelationManager;
use App\Filament\Resources\Recurrings\RelationManagers\RemindersRelationManager;
use App\Filament\Resources\Recurrings\Widgets\SetAsideOverview;
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

it('lists the soonest due first and items with nothing due last, and sorts latest due first', function () {
    $this->travelTo('2026-10-01');
    $rent = Recurring::factory()->for($this->account)->create(['name' => 'Rent', 'starts_on' => '2026-10-15']);
    $comcast = Recurring::factory()->for($this->account)->create(['name' => 'Comcast', 'starts_on' => '2026-10-25']);
    $laptop = Recurring::factory()->for($this->account)->create(['name' => 'Laptop', 'frequency' => Frequency::Once, 'starts_on' => '2026-10-12']);
    $laptop->occurrences()->sole()->update(['resolution' => OccurrenceResolution::Paid]);
    $this->travelTo('2026-10-21 12:00');

    Livewire::test(ListRecurrings::class)
        ->assertCanSeeTableRecords([$rent, $comcast, $laptop], inOrder: true)
        ->sortTable('next', 'desc')
        ->assertCanSeeTableRecords([$comcast, $rent, $laptop], inOrder: true);
});

it('badges Recurring with how many of your items are overdue', function () {
    $this->travelTo('2026-10-01');
    Recurring::factory()->for($this->account)->create(['frequency' => Frequency::Weekly, 'starts_on' => '2026-10-08']);
    Recurring::factory()->for($this->account)->income()->create(['starts_on' => '2026-10-20']);
    Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-25']);
    Recurring::factory()->for($this->account)->flex(Category::factory()->create())->create(['starts_on' => '2026-10-01']);
    Recurring::factory()->create(['starts_on' => '2026-10-15']);
    $this->travelTo('2026-10-21 12:00');

    expect(RecurringResource::getNavigationBadge())->toBe('2');
});

it('hides the Recurring badge when nothing is overdue', function () {
    $this->travelTo('2026-10-01');
    $rent = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-15']);
    $rent->occurrences()->whereDate('due_on', '2026-10-15')->sole()->update(['resolution' => OccurrenceResolution::Paid]);
    $this->travelTo('2026-10-21 12:00');

    expect(RecurringResource::getNavigationBadge())->toBeNull();
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

it('refuses a first due date with a mistyped year', function () {
    Livewire::test(CreateRecurring::class)
        ->fillForm(['name' => 'Rent', 'account_id' => $this->account->id, 'amount' => 900, 'starts_on' => '1026-11-01'])
        ->call('create')
        ->assertHasFormErrors(['starts_on']);

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

it('postpones a due date to a later date, and restores it', function () {
    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-25']);
    $occurrence = $comcast->occurrences()->whereDate('due_on', '2026-10-25')->sole();
    $dueDates = fn () => Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $comcast, 'pageClass' => ViewRecurring::class]);

    $dueDates()->callAction(TestAction::make('postpone')->table($occurrence), data: ['postponed_to' => '2026-10-25'])
        ->assertHasFormErrors(['postponed_to' => 'Choose a date after the due date.']);
    expect($occurrence->fresh()->postponed_to)->toBeNull();

    $dueDates()->callAction(TestAction::make('postpone')->table($occurrence), data: ['postponed_to' => '2026-11-03'])
        ->assertHasNoFormErrors()
        ->assertSee('postponed to Nov 3');
    expect($occurrence->fresh()->postponed_to->toDateString())->toBe('2026-11-03');

    $dueDates()->callAction(TestAction::make('restoreDueDate')->table($occurrence));
    expect($occurrence->fresh()->postponed_to)->toBeNull();
});

it('adds a reminder to a bill and shows when it will be sent', function () {
    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-25']);
    $reminders = fn () => Livewire::test(RemindersRelationManager::class, ['ownerRecord' => $comcast, 'pageClass' => ViewRecurring::class]);

    $reminders()->callAction(TestAction::make('create')->table(), data: ['days_before' => 61, 'repeats' => false])
        ->assertHasFormErrors(['days_before' => 'max']);

    $reminders()->callAction(TestAction::make('create')->table(), data: ['days_before' => 3, 'repeats' => true, 'note' => 'Pay by hand'])
        ->assertHasNoFormErrors()
        ->assertSee('3 days before, every due date')
        ->assertSee('Thu, Oct 22 for Oct 25');

    expect($comcast->reminders()->sole())
        ->days_before->toBe(3)
        ->repeats->toBeTrue()
        ->note->toBe('Pay by hand');
});

it('offers reminders on bills and income but not on flex spends', function () {
    $comcast = Recurring::factory()->for($this->account)->create();
    $groceries = Recurring::factory()->for($this->account)->flex(Category::factory()->create())->create();

    expect(RemindersRelationManager::canViewForRecord($comcast, ViewRecurring::class))->toBeTrue()
        ->and(RemindersRelationManager::canViewForRecord($groceries, ViewRecurring::class))->toBeFalse();
});

it('saves whether a bill is on autopay', function () {
    Livewire::test(CreateRecurring::class)
        ->fillForm([
            'kind' => RecurringKind::Bill->value,
            'name' => 'Insurance',
            'account_id' => $this->account->id,
            'amount' => 120,
            'frequency' => Frequency::Monthly->value,
            'starts_on' => '2026-10-28',
            'autopay' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Recurring::sole()->autopay)->toBeTrue();
});

it('flags the bills you pay by hand and filters to them', function () {
    $autopay = Recurring::factory()->for($this->account)->create(['name' => 'Insurance', 'autopay' => true]);
    $byHand = Recurring::factory()->for($this->account)->create(['name' => 'Water']);
    $income = Recurring::factory()->for($this->account)->income()->create();

    Livewire::test(ListRecurrings::class)
        ->assertTableColumnStateSet('autopay', 'Autopay', $autopay)
        ->assertTableColumnStateSet('autopay', 'Pay by hand', $byHand)
        ->assertTableColumnStateSet('autopay', null, $income)
        ->filterTable('autopay', false)
        ->assertCanSeeTableRecords([$byHand])
        ->assertCanNotSeeTableRecords([$autopay, $income]);
});

it('shows each period’s budget with what rolled over when a flex spend rolls over', function () {
    $this->travelTo('2026-10-01 12:00');
    $category = Category::factory()->create(['name' => 'Groceries']);
    $groceries = Recurring::factory()->for($this->account)->flex($category)->create(['amount' => 100, 'starts_on' => '2026-10-12', 'rolls_over' => true]);
    Transaction::factory()->for($this->account)->create(['amount' => 70, 'category_id' => $category->id, 'date' => '2026-10-13', 'authorized_date' => '2026-10-13']);
    $this->travelTo('2026-10-21 12:00');

    $thisWeek = $groceries->occurrences()->whereDate('due_on', '2026-10-19')->sole();

    Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $groceries, 'pageClass' => ViewRecurring::class])
        ->assertTableColumnStateSet('budget', 130.0, $thisWeek)
        ->assertTableColumnHasDescription('budget', '+$30.00 rolled over', $thisWeek);
});

it('totals what to set aside for quarterly and yearly bills, and shows it on each bill', function () {
    $insurance = Recurring::factory()->for($this->account)->create(['name' => 'Insurance', 'amount' => 1200, 'frequency' => Frequency::Yearly, 'starts_on' => '2027-01-01']);
    Recurring::factory()->for($this->account)->create(['name' => 'Tax', 'amount' => 300, 'frequency' => Frequency::EveryThreeMonths, 'starts_on' => '2026-11-15']);
    Recurring::factory()->for(Account::factory())->create(['amount' => 6000, 'frequency' => Frequency::Yearly, 'starts_on' => '2027-01-01']);

    Livewire::test(SetAsideOverview::class)
        ->assertSee('$200.00')
        ->assertSee('Across 2 bills')
        ->assertSee('$1,181.77');

    Livewire::test(ListRecurrings::class)
        ->assertTableColumnHasDescription('amount', 'per year · $100.00/mo to set aside', $insurance);

    Livewire::test(ViewRecurring::class, ['record' => $insurance->getRouteKey()])
        ->assertSee('$100.00 a month')
        ->assertSee('$963.29 by now for Jan 1');
});

it('hides the set-aside totals when there are no quarterly or yearly bills', function () {
    Recurring::factory()->for($this->account)->create();

    expect(SetAsideOverview::canView())->toBeFalse();
});
