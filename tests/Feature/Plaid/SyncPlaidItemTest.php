<?php

use App\Enums\CategorySource;
use App\Enums\MatchSource;
use App\Enums\PlaidItemStatus;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\PlaidItem;
use App\Models\Recurring;
use App\Models\Transaction;
use App\Services\Plaid\SyncPlaidItem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->book = Book::factory()->create();
    $this->item = PlaidItem::factory()->for($this->book->user)->create(['sync_cursor' => null]);
    $this->account = Account::factory()->for($this->item)->create([
        'book_id' => $this->book->id,
        'plaid_account_id' => 'acc-checking',
    ]);
});

function fakeSyncPages(array ...$responses): void
{
    $sequence = Http::sequence();

    foreach ($responses as $response) {
        isset($response['status'])
            ? $sequence->push($response['body'], $response['status'])
            : $sequence->push($response);
    }

    Http::fake([PLAID.'/transactions/sync' => $sequence]);
}

function sync(PlaidItem $item)
{
    return app(SyncPlaidItem::class)->handle($item->fresh());
}

it('pages through updates and saves the cursor only at the end', function () {
    fakeSyncPages(
        syncPage(added: [plaidTransaction(['transaction_id' => 'tx-1'])], hasMore: true, nextCursor: 'cursor-1'),
        syncPage(added: [plaidTransaction(['transaction_id' => 'tx-2'])], nextCursor: 'cursor-2'),
    );

    $result = sync($this->item);

    expect($result->successful)->toBeTrue()
        ->and($result->added)->toBe(2)
        ->and(Transaction::count())->toBe(2);

    $item = $this->item->fresh();
    expect($item->sync_cursor)->toBe('cursor-2')
        ->and($item->status)->toBe(PlaidItemStatus::Active)
        ->and($item->transactions_update_status)->toBe('HISTORICAL_UPDATE_COMPLETE')
        ->and($item->last_synced_at)->not->toBeNull();

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
    expect(isset($sent[0]['cursor']))->toBeFalse()
        ->and($sent[1]['cursor'])->toBe('cursor-1');
});

it('maps Plaid transactions, keeping Plaid’s sign convention', function () {
    fakeSyncPages(syncPage(added: [
        plaidTransaction(['transaction_id' => 'coffee', 'amount' => 4.75]),
        plaidTransaction(['transaction_id' => 'payroll', 'amount' => -2500, 'merchant_name' => null, 'name' => 'ACME PAYROLL']),
    ]));

    sync($this->item);

    $coffee = Transaction::where('plaid_transaction_id', 'coffee')->first();
    $payroll = Transaction::where('plaid_transaction_id', 'payroll')->first();

    expect($coffee->account_id)->toBe($this->account->id)
        ->and((float) $coffee->amount)->toBe(4.75)
        ->and($coffee->signed_amount)->toBe(-4.75)
        ->and($coffee->isInflow())->toBeFalse()
        ->and($coffee->description)->toBe('Starbucks')
        ->and($coffee->display_date->toDateString())->toBe('2026-10-19')
        ->and($coffee->category_primary)->toBe('FOOD_AND_DRINK')
        ->and($coffee->raw['transaction_id'])->toBe('coffee')
        ->and($payroll->isInflow())->toBeTrue()
        ->and($payroll->signed_amount)->toBe(2500.0)
        ->and($payroll->description)->toBe('ACME PAYROLL');
});

it('applies modified and removed transactions', function () {
    Transaction::factory()->for($this->account)->create(['plaid_transaction_id' => 'tx-1', 'amount' => 10]);
    Transaction::factory()->for($this->account)->create(['plaid_transaction_id' => 'tx-2']);

    fakeSyncPages(syncPage(
        modified: [plaidTransaction(['transaction_id' => 'tx-1', 'amount' => 15])],
        removed: ['tx-2'],
    ));

    $result = sync($this->item);

    expect($result->modified)->toBe(1)
        ->and($result->removed)->toBe(1)
        ->and(Transaction::pluck('plaid_transaction_id')->all())->toBe(['tx-1'])
        ->and((float) Transaction::first()->amount)->toBe(15.0);
});

it('replaces a pending transaction when it posts in the same sync', function () {
    Transaction::factory()->for($this->account)->pending()->create(['plaid_transaction_id' => 'pending-1', 'amount' => 20]);

    fakeSyncPages(syncPage(
        added: [plaidTransaction(['transaction_id' => 'posted-1', 'amount' => 20, 'pending_transaction_id' => 'pending-1'])],
        removed: ['pending-1'],
    ));

    sync($this->item);

    expect(Transaction::pluck('plaid_transaction_id')->all())->toBe(['posted-1'])
        ->and(Transaction::first()->pending)->toBeFalse();
});

it('does not double count when the pending removal arrives in a later sync', function () {
    fakeSyncPages(
        syncPage(added: [plaidTransaction(['transaction_id' => 'pending-1', 'pending' => true, 'amount' => 42])], nextCursor: 'c1'),
        syncPage(added: [plaidTransaction(['transaction_id' => 'posted-1', 'amount' => 42, 'pending_transaction_id' => 'pending-1'])], nextCursor: 'c2'),
        syncPage(removed: ['pending-1'], nextCursor: 'c3'),
    );

    sync($this->item);
    expect(Transaction::onlyPending()->count())->toBe(1);

    sync($this->item);
    expect(Transaction::count())->toBe(1)
        ->and((float) Transaction::sum('amount'))->toBe(42.0)
        ->and(Transaction::first()->plaid_transaction_id)->toBe('posted-1');

    // The late removal is now a harmless no-op.
    $result = sync($this->item);
    expect($result->successful)->toBeTrue()
        ->and(Transaction::count())->toBe(1);
});

it('keeps the old cursor when a page fails, and replays without duplicates', function () {
    fakeSyncPages(
        syncPage(added: [plaidTransaction(['transaction_id' => 'tx-1'])], hasMore: true, nextCursor: 'cursor-1'),
        plaidError('INTERNAL_SERVER_ERROR', 'API_ERROR', 500),
        // Retry starts from the beginning again.
        syncPage(added: [plaidTransaction(['transaction_id' => 'tx-1'])], hasMore: true, nextCursor: 'cursor-1'),
        syncPage(added: [plaidTransaction(['transaction_id' => 'tx-2'])], nextCursor: 'cursor-2'),
    );

    $failed = sync($this->item);

    expect($failed->successful)->toBeFalse()
        ->and($this->item->fresh()->sync_cursor)->toBeNull()
        ->and($this->item->fresh()->status)->toBe(PlaidItemStatus::Error)
        ->and($this->item->fresh()->error_code)->toBe('INTERNAL_SERVER_ERROR');

    $retry = sync($this->item);

    expect($retry->successful)->toBeTrue()
        ->and(Transaction::count())->toBe(2)
        ->and($this->item->fresh()->sync_cursor)->toBe('cursor-2')
        ->and($this->item->fresh()->status)->toBe(PlaidItemStatus::Active)
        ->and($this->item->fresh()->error_code)->toBeNull();
});

it('restarts from the original cursor when data changes mid-pagination', function () {
    $this->item->update(['sync_cursor' => 'cursor-start']);

    fakeSyncPages(
        syncPage(added: [plaidTransaction(['transaction_id' => 'tx-1'])], hasMore: true, nextCursor: 'cursor-a'),
        plaidError('TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION', 'TRANSACTIONS_ERROR'),
        syncPage(added: [plaidTransaction(['transaction_id' => 'tx-1'])], hasMore: true, nextCursor: 'cursor-b'),
        syncPage(added: [plaidTransaction(['transaction_id' => 'tx-2'])], nextCursor: 'cursor-end'),
    );

    $result = sync($this->item);

    $cursors = Http::recorded()->map(fn ($pair) => $pair[0]['cursor'] ?? null)->all();

    expect($result->successful)->toBeTrue()
        ->and($cursors)->toBe(['cursor-start', 'cursor-a', 'cursor-start', 'cursor-b'])
        ->and(Transaction::count())->toBe(2)
        ->and($this->item->fresh()->sync_cursor)->toBe('cursor-end');
});

it('flags items that need the user to log in again', function () {
    fakeSyncPages(plaidError('ITEM_LOGIN_REQUIRED', displayMessage: 'Sign in to your bank again.'));

    $result = sync($this->item);

    $item = $this->item->fresh();
    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Sign in to your bank again.')
        ->and($item->status)->toBe(PlaidItemStatus::LoginRequired)
        ->and($item->needsReconnect())->toBeTrue()
        ->and($item->error_message)->toBe('Sign in to your bank again.');
});

it('refreshes balances and adds new accounts as unassigned without renaming existing ones', function () {
    $this->account->update(['name' => 'Ops Checking']);

    fakeSyncPages(syncPage(accounts: [
        plaidAccount(['balances' => ['current' => 500, 'available' => 450]]),
        plaidAccount(['account_id' => 'acc-savings', 'name' => 'Plaid Saving', 'subtype' => 'savings']),
    ]));

    sync($this->item);

    $checking = $this->account->fresh();
    $savings = Account::where('plaid_account_id', 'acc-savings')->first();

    expect($checking->name)->toBe('Ops Checking')
        ->and((float) $checking->current_balance)->toBe(500.0)
        ->and((float) $checking->available_balance)->toBe(450.0)
        ->and($savings->book_id)->toBeNull()
        ->and($savings->plaid_item_id)->toBe($this->item->id);
});

it('stores an empty cursor as null so the next sync starts fresh', function () {
    fakeSyncPages(syncPage(nextCursor: '', status: 'NOT_READY'));

    sync($this->item);

    expect($this->item->fresh()->sync_cursor)->toBeNull()
        ->and($this->item->fresh()->isHistoryLoading())->toBeTrue();
});

it('never touches another item’s transactions', function () {
    $other = Transaction::factory()->create(['plaid_transaction_id' => 'tx-other']);

    fakeSyncPages(syncPage(removed: ['tx-other']));

    sync($this->item);

    expect($other->fresh())->not->toBeNull();
});

it('authenticates sync requests with the item’s access token', function () {
    fakeSyncPages(syncPage());

    sync($this->item);

    Http::assertSent(fn (Request $request) => $request['access_token'] === $this->item->access_token);
});

it('suggests categories for synced transactions', function () {
    $set = CategorySet::factory()->for($this->book->user)->create();
    $coffee = Category::factory()->for($set)->create(['plaid_categories' => ['FOOD_AND_DRINK_COFFEE']]);
    $this->book->update(['category_set_id' => $set->id]);
    fakeSyncPages(syncPage(added: [plaidTransaction()]));

    sync($this->item);

    expect(Transaction::sole())->category_id->toBe($coffee->id)->category_source->toBe(CategorySource::Suggested);
});

it('keeps the category you picked when a pending transaction posts', function () {
    $set = CategorySet::factory()->for($this->book->user)->create();
    Category::factory()->for($set)->create(['plaid_categories' => ['FOOD_AND_DRINK_COFFEE']]);
    $meals = Category::factory()->for($set)->create();
    $this->book->update(['category_set_id' => $set->id]);
    fakeSyncPages(
        syncPage(added: [plaidTransaction(['transaction_id' => 'pending-1', 'pending' => true])], nextCursor: 'c1'),
        syncPage(added: [plaidTransaction(['transaction_id' => 'posted-1', 'pending_transaction_id' => 'pending-1'])], nextCursor: 'c2'),
    );

    sync($this->item);
    Transaction::sole()->update(['category_id' => $meals->id, 'category_source' => CategorySource::Manual]);
    sync($this->item);

    expect(Transaction::sole())
        ->plaid_transaction_id->toBe('posted-1')
        ->category_id->toBe($meals->id)
        ->category_source->toBe(CategorySource::Manual);
});

it('links bills after a sync and keeps your link when the pending transaction posts', function () {
    $this->travelTo('2026-10-21');
    $starbucks = Recurring::factory()->for($this->account)->create(['payee' => 'starbucks', 'amount' => 12.50, 'starts_on' => '2026-10-19']);
    fakeSyncPages(
        syncPage(added: [plaidTransaction(['transaction_id' => 'pending-1', 'pending' => true])], nextCursor: 'c1'),
        syncPage(added: [plaidTransaction(['transaction_id' => 'posted-1', 'pending_transaction_id' => 'pending-1'])], nextCursor: 'c2'),
    );

    sync($this->item);
    $occurrence = $starbucks->occurrences()->whereNotNull('transaction_id')->sole();
    $occurrence->update(['matched_by' => MatchSource::Manual]);
    sync($this->item);

    expect($occurrence->fresh())
        ->transaction->plaid_transaction_id->toBe('posted-1')
        ->matched_by->toBe(MatchSource::Manual);
});

it('gives a bill’s category to the payment a sync links, and to the posted one that replaces it', function () {
    $this->travelTo('2026-10-21');
    $set = CategorySet::factory()->for($this->book->user)->create();
    Category::factory()->for($set)->create(['plaid_categories' => ['FOOD_AND_DRINK_COFFEE']]);
    $meals = Category::factory()->for($set)->create();
    $this->book->update(['category_set_id' => $set->id]);
    Recurring::factory()->for($this->account)->create(['payee' => 'starbucks', 'amount' => 12.50, 'starts_on' => '2026-10-19', 'category_id' => $meals->id]);
    fakeSyncPages(
        syncPage(added: [plaidTransaction(['transaction_id' => 'pending-1', 'pending' => true])], nextCursor: 'c1'),
        syncPage(added: [plaidTransaction(['transaction_id' => 'posted-1', 'pending_transaction_id' => 'pending-1'])], nextCursor: 'c2'),
    );

    sync($this->item);
    expect(Transaction::sole())->category_id->toBe($meals->id)->category_source->toBe(CategorySource::Recurring);

    sync($this->item);
    expect(Transaction::sole())
        ->plaid_transaction_id->toBe('posted-1')
        ->category_id->toBe($meals->id)
        ->category_source->toBe(CategorySource::Recurring);
});

it('does not link a transaction you unlinked once it posts', function () {
    $this->travelTo('2026-10-21');
    $starbucks = Recurring::factory()->for($this->account)->create(['payee' => 'starbucks', 'amount' => 12.50, 'starts_on' => '2026-10-19']);
    fakeSyncPages(
        syncPage(added: [plaidTransaction(['transaction_id' => 'pending-1', 'pending' => true])], nextCursor: 'c1'),
        syncPage(added: [plaidTransaction(['transaction_id' => 'posted-1', 'pending_transaction_id' => 'pending-1'])], nextCursor: 'c2'),
    );

    sync($this->item);
    $starbucks->occurrences()->whereNotNull('transaction_id')->sole()->unlink();
    sync($this->item);

    expect(Transaction::sole()->plaid_transaction_id)->toBe('posted-1')
        ->and($starbucks->occurrences()->whereNotNull('transaction_id')->exists())->toBeFalse();
});
