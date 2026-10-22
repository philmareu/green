<?php

use App\Filament\Widgets\BooksOverview;
use App\Filament\Widgets\PendingTransactions;
use App\Models\Account;
use App\Models\Book;
use App\Models\PlaidItem;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('requires sign in', function () {
    auth()->logout();

    $this->get('/')->assertRedirect('/login');
});

it('renders the dashboard', function () {
    $this->get('/')->assertOk()->assertSee('Books');
});

it('shows a card per Book with cash available', function () {
    $book = Book::factory()->for($this->user)->create(['name' => 'Acme LLC']);
    Account::factory()->inBook($book)->create(['available_balance' => 1234.56]);
    Account::factory()->inBook($book)->credit()->create(['current_balance' => 200]);

    Livewire::test(BooksOverview::class)
        ->assertSee('Acme LLC')
        ->assertSee('$1,234.56')
        ->assertSee('$200.00 credit owed');
});

it('prompts you to create a Book first', function () {
    Livewire::test(BooksOverview::class)->assertSee('No Books yet');
});

it('warns about connections that need attention', function () {
    Book::factory()->for($this->user)->create();
    PlaidItem::factory()->for($this->user)->loginRequired()->create();

    Livewire::test(BooksOverview::class)->assertSee('1 needs attention');
});

it('lists pending transactions across every Book', function () {
    $business = Account::factory()->inBook(Book::factory()->for($this->user)->business()->create())->create();
    $personal = Account::factory()->inBook(Book::factory()->for($this->user)->personal()->create())->create();
    $pendingA = Transaction::factory()->for($business)->pending()->create();
    $pendingB = Transaction::factory()->for($personal)->pending()->create();
    $posted = Transaction::factory()->for($business)->create();

    Livewire::test(PendingTransactions::class)
        ->assertCanSeeTableRecords([$pendingA, $pendingB])
        ->assertCanNotSeeTableRecords([$posted]);
});
