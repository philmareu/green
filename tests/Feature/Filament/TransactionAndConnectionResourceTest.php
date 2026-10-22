<?php

use App\Filament\Resources\PlaidItems\Pages\ListPlaidItems;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Book;
use App\Models\PlaidItem;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lists only your transactions and filters pending ones', function () {
    $book = Book::factory()->for($this->user)->create();
    $account = Account::factory()->inBook($book)->create();
    $posted = Transaction::factory()->for($account)->create();
    $pending = Transaction::factory()->for($account)->pending()->create();
    $theirs = Transaction::factory()->create();

    Livewire::test(ListTransactions::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$posted, $pending])
        ->assertCanNotSeeTableRecords([$theirs])
        ->filterTable('pending', true)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$posted]);
});

it('filters transactions by Book', function () {
    $business = Book::factory()->for($this->user)->business()->create();
    $personal = Book::factory()->for($this->user)->personal()->create();
    $item = PlaidItem::factory()->for($this->user)->create();
    $inBusiness = Transaction::factory()->for(Account::factory()->for($item)->create(['book_id' => $business->id]))->create();
    $inPersonal = Transaction::factory()->for(Account::factory()->for($item)->create(['book_id' => $personal->id]))->create();

    Livewire::test(ListTransactions::class)
        ->filterTable('book', $business->id)
        ->assertCanSeeTableRecords([$inBusiness])
        ->assertCanNotSeeTableRecords([$inPersonal]);
});

it('lists your bank connections', function () {
    $mine = PlaidItem::factory()->for($this->user)->loginRequired()->create();
    $theirs = PlaidItem::factory()->create();

    Livewire::test(ListPlaidItems::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertActionVisible(TestAction::make('reconnect')->table($mine));
});

it('removes a connection at Plaid and locally', function () {
    $item = PlaidItem::factory()->for($this->user)->create();
    $account = Account::factory()->for($item)->create();
    Transaction::factory()->for($account)->create();
    Http::fake([PLAID.'/item/remove' => Http::response(['request_id' => 'r'])]);

    Livewire::test(ListPlaidItems::class)
        ->callAction(TestAction::make('remove')->table($item))
        ->assertNotified('Bank connection removed');

    expect(PlaidItem::count())->toBe(0)
        ->and(Account::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);

    Http::assertSent(fn (Request $request) => $request['access_token'] === $item->access_token);
});

it('keeps the connection if Plaid refuses to remove it', function () {
    $item = PlaidItem::factory()->for($this->user)->create();
    Http::fake([PLAID.'/item/remove' => Http::response(...plaidError('INTERNAL_SERVER_ERROR', 'API_ERROR', 500))]);

    Livewire::test(ListPlaidItems::class)
        ->callAction(TestAction::make('remove')->table($item))
        ->assertNotified('Could not remove connection');

    expect($item->fresh())->not->toBeNull();
});
