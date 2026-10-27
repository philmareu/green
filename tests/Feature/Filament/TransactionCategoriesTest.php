<?php

use App\Enums\CategorySource;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->set = CategorySet::factory()->for($this->user)->create();
    $book = Book::factory()->for($this->user)->create(['category_set_id' => $this->set->id]);
    $this->transaction = Transaction::factory()->for(Account::factory()->inBook($book))->create();
});

function pickCategory(Transaction $transaction, mixed $categoryId): mixed
{
    return Livewire::test(ListTransactions::class)
        ->call('updateTableColumnState', 'category_id', (string) $transaction->getKey(), $categoryId);
}

it('picks and clears a transaction’s category from the table', function () {
    $category = Category::factory()->for($this->set)->create();

    pickCategory($this->transaction, (string) $category->id);
    expect($this->transaction->fresh())->category_id->toBe($category->id)->category_source->toBe(CategorySource::Manual);

    pickCategory($this->transaction, '');
    expect($this->transaction->fresh())->category_id->toBeNull()->category_source->toBeNull();
});

it('refuses a category from another set', function () {
    $elsewhere = Category::factory()->for(CategorySet::factory()->for($this->user))->create();

    pickCategory($this->transaction, (string) $elsewhere->id);

    expect($this->transaction->fresh()->category_id)->toBeNull();
});

it('filters uncategorized transactions, and a parent category with its subcategories', function () {
    $food = Category::factory()->for($this->set)->create();
    $coffee = Category::factory()->childOf($food)->create();
    $travel = Category::factory()->for($this->set)->create();
    $latte = Transaction::factory()->for($this->transaction->account)->create(['category_id' => $coffee->id]);
    $flight = Transaction::factory()->for($this->transaction->account)->create(['category_id' => $travel->id]);

    Livewire::test(ListTransactions::class)
        ->filterTable('category', 'uncategorized')
        ->assertCanSeeTableRecords([$this->transaction])
        ->assertCanNotSeeTableRecords([$latte, $flight])
        ->filterTable('category', $food->id)
        ->assertCanSeeTableRecords([$latte])
        ->assertCanNotSeeTableRecords([$this->transaction, $flight]);
});
