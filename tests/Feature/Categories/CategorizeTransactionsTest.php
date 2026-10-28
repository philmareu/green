<?php

use App\Enums\CategorySource;
use App\Enums\MatchSource;
use App\Enums\RecurringKind;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\Recurring;
use App\Models\Transaction;
use App\Services\Categories\CategorizeTransactions;

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00');
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

/**
 * A payment of the factory's Comcast bill that Plaid files under food.
 */
function billPayment(Account $account, string $date, array $attributes = []): Transaction
{
    return plaidCategorized($account, 'FOOD_AND_DRINK', null, [
        'name' => 'COMCAST CABLE 8823',
        'merchant_name' => null,
        'amount' => 89.00,
        'date' => $date,
        'authorized_date' => $date,
        ...$attributes,
    ]);
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

it('gives a bill’s category to its payments instead of Plaid’s suggestion, but not over one you picked', function () {
    $internet = Category::factory()->for($this->set)->create(['name' => 'Internet']);
    $september = billPayment($this->account, '2026-09-15');
    $october = billPayment($this->account, '2026-10-15', ['category_id' => $this->coffee->id, 'category_source' => CategorySource::Manual]);
    categorizeAll();

    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-09-15', 'category_id' => $internet->id]);

    expect($comcast->occurrences()->whereNotNull('transaction_id')->count())->toBe(2)
        ->and($september->fresh())->category_id->toBe($internet->id)->category_source->toBe(CategorySource::Recurring)
        ->and($october->fresh())->category_id->toBe($this->coffee->id)->category_source->toBe(CategorySource::Manual);
});

it('follows a bill’s category when you change or clear it', function () {
    $internet = Category::factory()->for($this->set)->create(['name' => 'Internet']);
    $phone = Category::factory()->for($this->set)->create(['name' => 'Phone']);
    $payment = billPayment($this->account, '2026-10-15');
    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-15', 'category_id' => $internet->id]);

    $comcast->update(['category_id' => $phone->id]);
    expect($payment->fresh()->category_id)->toBe($phone->id);

    $comcast->update(['category_id' => null]);
    expect($payment->fresh())->category_id->toBe($this->food->id)->category_source->toBe(CategorySource::Suggested);
});

it('gives payments back Plaid’s suggestion when unlinked or when their bill is deleted', function () {
    $internet = Category::factory()->for($this->set)->create(['name' => 'Internet']);
    $september = billPayment($this->account, '2026-09-15');
    $october = billPayment($this->account, '2026-10-15');
    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-09-15', 'category_id' => $internet->id]);

    $comcast->occurrences()->where('transaction_id', $september->id)->sole()->unlink();
    expect($september->fresh())->category_id->toBe($this->food->id)->category_source->toBe(CategorySource::Suggested)
        ->and($october->fresh()->category_id)->toBe($internet->id);

    $comcast->delete();
    expect($october->fresh())->category_id->toBe($this->food->id)->category_source->toBe(CategorySource::Suggested);
});

it('never gives a flex spend’s budget category to a transaction linked to it', function () {
    $internet = Category::factory()->for($this->set)->create(['name' => 'Internet']);
    $transfer = billPayment($this->account, '2026-10-15', ['name' => 'ONLINE TRANSFER 3321']);
    $comcast = Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-15', 'category_id' => $internet->id]);
    $comcast->occurrences()->whereDate('due_on', '2026-10-15')->sole()->link($transfer, MatchSource::Manual);
    expect($transfer->fresh()->category_id)->toBe($internet->id);

    $comcast->update(['kind' => RecurringKind::Flex, 'payee' => null]);

    expect($transfer->fresh())->category_id->toBe($this->food->id)->category_source->toBe(CategorySource::Suggested);
});

it('gives a transaction the category with the longest payee its description contains, ahead of Plaid’s suggestion', function () {
    $supplies = Category::factory()->for($this->set)->create(['name' => 'Supplies', 'payees' => ['amazon']]);
    $hosting = Category::factory()->for($this->set)->create(['name' => 'Hosting', 'payees' => ['Amazon Web Services']]);
    $order = plaidCategorized($this->account, 'FOOD_AND_DRINK', null, ['name' => 'AMAZON MKTPL*2K4', 'merchant_name' => null]);
    $servers = plaidCategorized($this->account, 'FOOD_AND_DRINK', null, ['name' => 'AWS', 'merchant_name' => 'Amazon Web Services']);

    categorizeAll();

    expect($order->fresh())->category_id->toBe($supplies->id)->category_source->toBe(CategorySource::Payee)
        ->and($servers->fresh()->category_id)->toBe($hosting->id);
});

it('keeps your pick and a bill’s category over a payee match', function () {
    $internet = Category::factory()->for($this->set)->create(['name' => 'Internet']);
    Category::factory()->for($this->set)->create(['name' => 'Cable', 'payees' => ['comcast']]);
    $billed = billPayment($this->account, '2026-10-15');
    $picked = billPayment($this->account, '2026-09-01', ['category_id' => $this->coffee->id, 'category_source' => CategorySource::Manual]);
    Recurring::factory()->for($this->account)->create(['starts_on' => '2026-10-15', 'category_id' => $internet->id]);

    categorizeAll();

    expect($billed->fresh())->category_id->toBe($internet->id)->category_source->toBe(CategorySource::Recurring)
        ->and($picked->fresh()->category_id)->toBe($this->coffee->id);
});

it('goes back to Plaid’s suggestion when the payee is removed', function () {
    $cable = Category::factory()->for($this->set)->create(['name' => 'Cable', 'payees' => ['comcast']]);
    $payment = billPayment($this->account, '2026-10-01');
    categorizeAll();
    expect($payment->fresh()->category_id)->toBe($cable->id);

    $cable->update(['payees' => null]);
    categorizeAll();

    expect($payment->fresh())->category_id->toBe($this->food->id)->category_source->toBe(CategorySource::Suggested);
});
