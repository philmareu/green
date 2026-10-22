<?php

use App\Enums\BookKind;
use App\Filament\Resources\Books\Pages\CreateBook;
use App\Filament\Resources\Books\Pages\EditBook;
use App\Filament\Resources\Books\Pages\ListBooks;
use App\Filament\Resources\Books\Pages\ViewBook;
use App\Filament\Resources\Books\RelationManagers\AccountsRelationManager;
use App\Filament\Resources\Books\RelationManagers\TransactionsRelationManager;
use App\Models\Account;
use App\Models\Book;
use App\Models\CategorySet;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lists only your Books', function () {
    $mine = Book::factory()->for($this->user)->create();
    $theirs = Book::factory()->create();

    Livewire::test(ListBooks::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('creates a Book for the signed-in user', function () {
    Livewire::test(CreateBook::class)
        ->fillForm(['name' => 'Acme Consulting', 'kind' => BookKind::Business->value])
        ->call('create')
        ->assertHasNoFormErrors();

    $book = Book::sole();

    expect($book->user_id)->toBe($this->user->id)
        ->and($book->kind)->toBe(BookKind::Business);
});

it('suggests the category set matching a new Book’s kind', function () {
    $businessSet = CategorySet::factory()->for($this->user)->business()->create();
    $personalSet = CategorySet::factory()->for($this->user)->personal()->create();

    Livewire::test(CreateBook::class)
        ->assertSchemaStateSet(['category_set_id' => $businessSet->id])
        ->fillForm(['name' => 'Household', 'kind' => BookKind::Personal->value])
        ->assertSchemaStateSet(['category_set_id' => $personalSet->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Book::sole()->category_set_id)->toBe($personalSet->id);
});

it('will not use someone else’s category set', function () {
    Livewire::test(CreateBook::class)
        ->fillForm(['name' => 'Acme', 'kind' => BookKind::Business->value, 'category_set_id' => CategorySet::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['category_set_id']);
});

it('requires a name', function () {
    Livewire::test(CreateBook::class)
        ->fillForm(['name' => '', 'kind' => BookKind::Personal->value])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('shows a Book with its accounts and transactions', function () {
    $book = Book::factory()->for($this->user)->create();
    $account = Account::factory()->inBook($book)->create(['name' => 'Ops Checking']);
    $transaction = Transaction::factory()->for($account)->create();

    Livewire::test(ViewBook::class, ['record' => $book->getRouteKey()])
        ->assertOk()
        ->assertSee('Link bank account');

    Livewire::test(AccountsRelationManager::class, ['ownerRecord' => $book, 'pageClass' => ViewBook::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$account]);

    Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $book, 'pageClass' => ViewBook::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$transaction])
        ->filterTable('pending', true)
        ->assertCanNotSeeTableRecords([$transaction])
        ->searchTable($transaction->merchant_name)
        ->assertOk();
});

it('hides other users’ Books', function () {
    $theirs = Book::factory()->create();

    Livewire::test(ViewBook::class, ['record' => $theirs->getRouteKey()])->assertNotFound();
    Livewire::test(EditBook::class, ['record' => $theirs->getRouteKey()])->assertNotFound();
});

it('keeps accounts when a Book is deleted', function () {
    $book = Book::factory()->for($this->user)->create();
    $account = Account::factory()->inBook($book)->create();

    $book->delete();

    expect($account->fresh()->book_id)->toBeNull();
});

it('totals cash and credit separately', function () {
    $book = Book::factory()->for($this->user)->create();
    Account::factory()->inBook($book)->create(['current_balance' => 1000, 'available_balance' => 900]);
    Account::factory()->inBook($book)->create(['current_balance' => 50, 'available_balance' => null]);
    Account::factory()->inBook($book)->credit()->create(['current_balance' => 300, 'available_balance' => 1700]);
    Account::factory()->inBook($book)->create(['available_balance' => 5000, 'is_hidden' => true]);

    $book->load('accounts');

    expect($book->cashAvailable())->toBe(950.0)
        ->and($book->creditOwed())->toBe(300.0);
});
