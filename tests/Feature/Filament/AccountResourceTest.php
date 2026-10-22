<?php

use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\Book;
use App\Models\PlaidItem;
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
        ->assertHasNoFormErrors();

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
    Livewire::test(EditAccount::class, ['record' => Account::factory()->create()->getRouteKey()])
        ->assertNotFound();
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
