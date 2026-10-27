<?php

use App\Enums\CategorySource;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\Transaction;
use App\Services\Categories\CategorizeTransactions;

beforeEach(function () {
    $this->set = CategorySet::factory()->create();
    $this->book = Book::factory()->for($this->set->user)->create(['category_set_id' => $this->set->id]);
    $this->account = Account::factory()->inBook($this->book)->create();

    $this->food = Category::factory()->for($this->set)->create(['name' => 'Food', 'plaid_categories' => ['FOOD_AND_DRINK']]);
    $this->coffee = Category::factory()->childOf($this->food)->create(['name' => 'Coffee', 'plaid_categories' => ['FOOD_AND_DRINK_COFFEE']]);
});

function categorizeAll(): int
{
    return app(CategorizeTransactions::class)->handle(Transaction::query());
}

function plaidCategorized(Account $account, string $primary, ?string $detailed = null, array $attributes = []): Transaction
{
    return Transaction::factory()->for($account)->create(['category_primary' => $primary, 'category_detailed' => $detailed, ...$attributes]);
}

it('suggests a category from the detailed Plaid category before the primary one', function () {
    $latte = plaidCategorized($this->account, 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_COFFEE');
    $burger = plaidCategorized($this->account, 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_FAST_FOOD');
    $flight = plaidCategorized($this->account, 'TRAVEL', 'TRAVEL_FLIGHTS');

    expect(categorizeAll())->toBe(2)
        ->and($latte->fresh())->category_id->toBe($this->coffee->id)->category_source->toBe(CategorySource::Suggested)
        ->and($burger->fresh()->category_id)->toBe($this->food->id)
        ->and($flight->fresh())->category_id->toBeNull()->category_source->toBeNull();
});

it('never replaces a category you picked', function () {
    $latte = plaidCategorized($this->account, 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_COFFEE', [
        'category_id' => $this->food->id,
        'category_source' => CategorySource::Manual,
    ]);

    expect(categorizeAll())->toBe(0)
        ->and($latte->fresh()->category_id)->toBe($this->food->id);
});

it('updates suggestions when a category’s Plaid categories change', function () {
    $latte = plaidCategorized($this->account, 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_COFFEE');
    categorizeAll();

    $this->coffee->update(['plaid_categories' => []]);
    categorizeAll();
    expect($latte->fresh()->category_id)->toBe($this->food->id);

    $this->food->update(['plaid_categories' => []]);
    categorizeAll();
    expect($latte->fresh())->category_id->toBeNull()->category_source->toBeNull();
});

it('clears categories that are not in the transaction’s Book set', function () {
    $elsewhere = Category::factory()->create();
    $picked = plaidCategorized($this->account, 'TRAVEL', null, ['category_id' => $elsewhere->id, 'category_source' => CategorySource::Manual]);
    $unassigned = plaidCategorized(Account::factory()->create(['book_id' => null]), 'TRAVEL', null, [
        'category_id' => $this->food->id,
        'category_source' => CategorySource::Manual,
    ]);

    categorizeAll();

    expect($picked->fresh())->category_id->toBeNull()->category_source->toBeNull()
        ->and($unassigned->fresh()->category_id)->toBeNull();
});

it('recategorizes an account’s transactions when it moves to another Book', function () {
    $otherSet = CategorySet::factory()->for($this->set->user)->create();
    $cafes = Category::factory()->for($otherSet)->create(['plaid_categories' => ['FOOD_AND_DRINK_COFFEE']]);
    $otherBook = Book::factory()->for($this->set->user)->create(['category_set_id' => $otherSet->id]);
    $latte = plaidCategorized($this->account, 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_COFFEE', [
        'category_id' => $this->coffee->id,
        'category_source' => CategorySource::Manual,
    ]);

    $this->account->update(['book_id' => $otherBook->id]);

    expect($latte->fresh())->category_id->toBe($cafes->id)->category_source->toBe(CategorySource::Suggested);
});

it('recategorizes a Book’s transactions when it switches category sets', function () {
    $latte = plaidCategorized($this->account, 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_COFFEE');
    $this->book->update(['category_set_id' => null]);
    expect($latte->fresh()->category_id)->toBeNull();

    $this->book->update(['category_set_id' => $this->set->id]);
    expect($latte->fresh()->category_id)->toBe($this->coffee->id);
});

it('uncategorizes transactions when their Book is deleted', function () {
    $latte = plaidCategorized($this->account, 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_COFFEE', [
        'category_id' => $this->coffee->id,
        'category_source' => CategorySource::Manual,
    ]);

    $this->book->delete();

    expect($this->account->fresh()->book_id)->toBeNull()
        ->and($latte->fresh()->category_id)->toBeNull();
});
