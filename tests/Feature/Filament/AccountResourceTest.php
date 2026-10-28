<?php

use App\Enums\Frequency;
use App\Enums\RecurringKind;
use App\Filament\Actions\PlaidActions;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\RecurringsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Books\Pages\ViewBook;
use App\Filament\Resources\Books\RelationManagers\AccountsRelationManager;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\PlaidItem;
use App\Models\Recurring;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->book = Book::factory()->for($this->user)->create();
    $this->item = PlaidItem::factory()->for($this->user)->create();
});

it('lists only your visible accounts by default', function () {
    $visible = Account::factory()->for($this->item)->create(['book_id' => $this->book->id]);
    $hidden = Account::factory()->for($this->item)->create(['is_hidden' => true]);
    $theirs = Account::factory()->create();

    Livewire::test(ListAccounts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden, $theirs]);
});

it('filters to unassigned accounts', function () {
    $assigned = Account::factory()->for($this->item)->create(['book_id' => $this->book->id]);
    $unassigned = Account::factory()->for($this->item)->create(['book_id' => null]);

    Livewire::test(ListAccounts::class)
        ->filterTable('book', 'unassigned')
        ->assertCanSeeTableRecords([$unassigned])
        ->assertCanNotSeeTableRecords([$assigned]);
});

it('moves an account to another Book and renames it', function () {
    $account = Account::factory()->for($this->item)->create(['book_id' => null]);

    Livewire::test(EditAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['name' => 'Business Checking', 'book_id' => $this->book->id])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(AccountResource::getUrl('view', ['record' => $account]));

    expect($account->fresh())
        ->name->toBe('Business Checking')
        ->book_id->toBe($this->book->id);
});

it('will not assign an account to someone else’s Book', function () {
    $account = Account::factory()->for($this->item)->create();
    $theirs = Book::factory()->create();

    Livewire::test(EditAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['book_id' => $theirs->id])
        ->call('save')
        ->assertHasFormErrors(['book_id']);

    expect($account->fresh()->book_id)->toBeNull();
});

it('cannot open someone else’s account', function () {
    $theirs = Account::factory()->create();

    Livewire::test(ViewAccount::class, ['record' => $theirs->getRouteKey()])->assertNotFound();
    Livewire::test(EditAccount::class, ['record' => $theirs->getRouteKey()])->assertNotFound();
});

it('links account rows to the account page', function () {
    $account = Account::factory()->for($this->item)->create(['book_id' => $this->book->id]);
    $accountUrl = AccountResource::getUrl('view', ['record' => $account]);

    Livewire::test(ListAccounts::class)
        ->assertActionHasUrl(TestAction::make('view')->table($account), $accountUrl)
        ->assertActionHasUrl(TestAction::make('edit')->table($account), AccountResource::getUrl('edit', ['record' => $account]));

    Livewire::test(AccountsRelationManager::class, ['ownerRecord' => $this->book, 'pageClass' => ViewBook::class])
        ->assertActionHasUrl(TestAction::make('view')->table($account), $accountUrl);
});

it('shows an account with only its own transactions', function () {
    $account = Account::factory()->for($this->item)->create(['book_id' => $this->book->id, 'available_balance' => 1234.56]);
    $sibling = Account::factory()->for($this->item)->create(['book_id' => $this->book->id]);
    $posted = Transaction::factory()->for($account)->create();
    $pending = Transaction::factory()->for($account)->pending()->create();
    $elsewhere = Transaction::factory()->for($sibling)->create();

    Livewire::test(ViewAccount::class, ['record' => $account->getRouteKey()])
        ->assertOk()
        ->assertSee('$1,234.56')
        ->assertSee($this->book->name)
        ->assertActionVisible('edit');

    Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$posted, $pending])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

it('shows fresh balances after syncing from the account page', function () {
    $account = Account::factory()->for($this->item)->create(['plaid_account_id' => 'acc-checking', 'available_balance' => 1]);
    Http::fake([PLAID.'/transactions/sync' => Http::response(syncPage(accounts: [plaidAccount(['balances' => ['available' => 4321.5]])]))]);

    Livewire::test(ViewAccount::class, ['record' => $account->getRouteKey()])
        ->callAction('sync')
        ->assertNotified()
        ->assertSee('$4,321.50')
        ->assertDispatched(PlaidActions::SYNCED_EVENT);
});

it('syncs an account’s bank from the table', function () {
    $account = Account::factory()->for($this->item)->create(['plaid_account_id' => 'acc-checking']);
    Http::fake([PLAID.'/transactions/sync' => Http::response(syncPage(added: [plaidTransaction()]))]);

    Livewire::test(ListAccounts::class)
        ->callAction(TestAction::make('sync')->table($account))
        ->assertNotified();

    expect($account->transactions()->count())->toBe(1);
});

it('refreshes a live balance', function () {
    $account = Account::factory()->for($this->item)->create(['plaid_account_id' => 'acc-checking', 'available_balance' => 1]);
    Http::fake([PLAID.'/accounts/balance/get' => Http::response(['accounts' => [plaidAccount(['balances' => ['available' => 321.5]])], 'item' => []])]);

    Livewire::test(ListAccounts::class)
        ->callAction(TestAction::make('refreshLiveBalance')->table($account))
        ->assertNotified('Balance updated');

    expect((float) $account->fresh()->available_balance)->toBe(321.5);
});

it('shows only the account’s own recurring items', function () {
    $account = Account::factory()->for($this->item)->create(['book_id' => $this->book->id]);
    $sibling = Account::factory()->for($this->item)->create(['book_id' => $this->book->id]);
    $comcast = Recurring::factory()->for($account)->create();
    $elsewhere = Recurring::factory()->for($sibling)->create();

    Livewire::test(RecurringsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$comcast])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

it('adds a bill from the account page without picking the account, and links its earlier payment', function () {
    $this->travelTo('2026-10-21 12:00');
    $account = Account::factory()->for($this->item)->create(['book_id' => $this->book->id]);
    $payment = Transaction::factory()->for($account)->create([
        'date' => '2026-10-14',
        'authorized_date' => '2026-10-14',
        'amount' => 89.00,
        'name' => 'COMCAST CABLE 8823',
        'merchant_name' => null,
    ]);

    Livewire::test(RecurringsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'kind' => RecurringKind::Bill->value,
            'name' => 'Comcast',
            'amount' => 89,
            'frequency' => Frequency::Monthly->value,
            'starts_on' => '2026-10-15',
            'payee' => 'comcast',
            'amount_tolerance' => 10,
        ])
        ->assertHasNoFormErrors();

    $comcast = Recurring::sole();
    expect($comcast)
        ->account_id->toBe($account->id)
        ->user_id->toBe($this->user->id)
        ->and($comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole()->transaction_id)->toBe($payment->id);
});

it('offers a flex spend only the categories of the account’s Book', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $this->book->update(['category_set_id' => $set->id]);
    $account = Account::factory()->for($this->item)->create(['book_id' => $this->book->id]);
    $groceries = Category::factory()->for($set)->create(['name' => 'Groceries']);
    $elsewhere = Category::factory()->for(CategorySet::factory()->for($this->user))->create();
    $flex = ['kind' => RecurringKind::Flex->value, 'name' => 'Groceries', 'amount' => 150, 'frequency' => Frequency::Weekly->value, 'starts_on' => today()->toDateString()];

    Livewire::test(RecurringsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callAction(TestAction::make('create')->table(), data: [...$flex, 'category_id' => $elsewhere->id])
        ->assertHasFormErrors(['category_id']);

    Livewire::test(RecurringsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callAction(TestAction::make('create')->table(), data: [...$flex, 'category_id' => $groceries->id])
        ->assertHasNoFormErrors();

    expect(Recurring::sole())->category_id->toBe($groceries->id)->account_id->toBe($account->id);
});

it('edits a recurring item from the account page and keeps it on the account', function () {
    $account = Account::factory()->for($this->item)->create(['book_id' => $this->book->id]);
    $comcast = Recurring::factory()->for($account)->create();

    Livewire::test(RecurringsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->callAction(TestAction::make('edit')->table($comcast), data: ['amount' => 95])
        ->assertHasNoFormErrors();

    expect($comcast->fresh())
        ->amount->toEqual('95.0000')
        ->account_id->toBe($account->id);
});
