<?php

use App\Enums\CategorySource;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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

it('categorizes everything from a transaction’s payee, but not ones you picked yourself', function () {
    $software = Category::factory()->for($this->set)->create(['name' => 'Software']);
    $other = Category::factory()->for($this->set)->create(['name' => 'Other']);
    $this->transaction->update(['name' => 'SLACK T0123', 'merchant_name' => 'Slack']);
    $earlier = Transaction::factory()->for($this->transaction->account)->create(['name' => 'SLACK T0456', 'merchant_name' => null]);
    $picked = Transaction::factory()->for($this->transaction->account)->create([
        'name' => 'SLACK T0789',
        'merchant_name' => 'Slack',
        'category_id' => $other->id,
        'category_source' => CategorySource::Manual,
    ]);

    Livewire::test(ListTransactions::class)
        ->callAction(TestAction::make('categorizePayee')->table($this->transaction), data: ['category_id' => $software->id])
        ->assertHasNoFormErrors()
        ->assertNotified('“Slack” now goes in Software');

    expect($software->fresh()->payees)->toBe(['Slack'])
        ->and($this->transaction->fresh())->category_id->toBe($software->id)->category_source->toBe(CategorySource::Payee)
        ->and($earlier->fresh()->category_id)->toBe($software->id)
        ->and($picked->fresh()->category_id)->toBe($other->id);
});

it('refuses to categorize a payee with a category from another set', function () {
    $elsewhere = Category::factory()->for(CategorySet::factory()->for($this->user))->create();

    Livewire::test(ListTransactions::class)
        ->callAction(TestAction::make('categorizePayee')->table($this->transaction), data: ['category_id' => $elsewhere->id])
        ->assertHasFormErrors(['category_id']);

    expect($elsewhere->fresh()->payees)->toBeNull();
});

it('hides payee categorizing for a transaction whose Book has no category set', function () {
    $unassigned = Transaction::factory()->for(Account::factory()->for($this->transaction->account->plaidItem)->create(['book_id' => null]))->create();

    Livewire::test(ListTransactions::class)
        ->assertActionHidden(TestAction::make('categorizePayee')->table($unassigned));
});
