<?php

use App\Enums\PlaidLogKind;
use App\Enums\PlaidLogResult;
use App\Enums\PlaidLogTrigger;
use App\Filament\Pages\LinkBank;
use App\Jobs\SyncPlaidItemJob;
use App\Models\Account;
use App\Models\Book;
use App\Models\PlaidItem;
use App\Models\PlaidLog;
use App\Models\Transaction;
use App\Services\Plaid\RefreshLiveBalances;
use App\Services\Plaid\RemovePlaidItem;
use App\Services\Plaid\SyncPlaidItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('logs a scheduled sync’s pages, counts and Plaid request ids, without transaction details', function () {
    $item = PlaidItem::factory()->create(['institution_name' => 'First Platypus Bank']);
    $account = Account::factory()->for($item)->create(['plaid_account_id' => 'acc-checking']);
    Transaction::factory()->for($account)->create(['plaid_transaction_id' => 'tx-old']);
    Http::fake([PLAID.'/transactions/sync' => Http::sequence()
        ->push([...syncPage(added: [plaidTransaction(['transaction_id' => 'tx-1']), plaidTransaction(['transaction_id' => 'tx-2'])], hasMore: true, nextCursor: 'c1'), 'request_id' => 'req-page-1'])
        ->push([...syncPage(modified: [plaidTransaction(['transaction_id' => 'tx-1'])], removed: ['tx-old']), 'request_id' => 'req-page-2'])]);

    SyncPlaidItemJob::dispatch($item);

    expect(PlaidLog::sole())
        ->user_id->toBe($item->user_id)
        ->plaid_item_id->toBe($item->id)
        ->institution_name->toBe('First Platypus Bank')
        ->kind->toBe(PlaidLogKind::Sync)
        ->triggered_by->toBe(PlaidLogTrigger::Scheduled)
        ->result->toBe(PlaidLogResult::Succeeded)
        ->pages->toBe(2)
        ->added->toBe(2)
        ->modified->toBe(1)
        ->removed->toBe(1)
        ->duration_ms->toBeInt()
        ->requests->toEqual([
            ['endpoint' => '/transactions/sync', 'status' => 200, 'request_id' => 'req-page-1', 'error_code' => null],
            ['endpoint' => '/transactions/sync', 'status' => 200, 'request_id' => 'req-page-2', 'error_code' => null],
        ]);
    expect(DB::table('plaid_logs')->get()->toJson())
        ->not->toContain($item->access_token)
        ->not->toContain('STARBUCKS')
        ->not->toContain('12.5');
});

it('logs a failed sync’s error code without throwing', function () {
    $item = PlaidItem::factory()->create();
    Http::fake([PLAID.'/transactions/sync' => Http::response(...plaidError('ITEM_LOGIN_REQUIRED'))]);

    $result = app(SyncPlaidItem::class)->handle($item);

    expect($result->successful)->toBeFalse();
    expect(PlaidLog::sole())
        ->triggered_by->toBe(PlaidLogTrigger::Manual)
        ->result->toBe(PlaidLogResult::Failed)
        ->error_code->toBe('ITEM_LOGIN_REQUIRED')
        ->error_message->toBe('Plaid error ITEM_LOGIN_REQUIRED')
        ->pages->toBe(0)
        ->added->toBeNull()
        ->requests->toEqual([
            ['endpoint' => '/transactions/sync', 'status' => 400, 'request_id' => 'req-error', 'error_code' => 'ITEM_LOGIN_REQUIRED'],
        ]);
});

it('shows a sync as unfinished until it ends', function () {
    $item = PlaidItem::factory()->create();
    $resultWhileRunning = null;
    Http::fake([PLAID.'/transactions/sync' => function () use (&$resultWhileRunning) {
        $resultWhileRunning = PlaidLog::sole()->result;

        return Http::response(syncPage());
    }]);

    app(SyncPlaidItem::class)->handle($item);

    expect($resultWhileRunning)->toBe(PlaidLogResult::Unfinished)
        ->and(PlaidLog::sole()->result)->toBe(PlaidLogResult::Succeeded);
});

it('keeps syncing when the log can’t be written', function () {
    $item = PlaidItem::factory()->create();
    Http::fake([PLAID.'/transactions/sync' => Http::response(syncPage(nextCursor: 'cursor-2'))]);
    Exceptions::fake();
    PlaidLog::saving(fn () => throw new RuntimeException('The log is unavailable.'));

    $result = app(SyncPlaidItem::class)->handle($item);

    expect($result->successful)->toBeTrue()
        ->and($item->fresh()->sync_cursor)->toBe('cursor-2');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'The log is unavailable.');
});

it('logs each Live balance refresh, which Plaid bills per call', function () {
    $item = PlaidItem::factory()->create();
    Http::fake([PLAID.'/accounts/balance/get' => Http::response(['accounts' => [plaidAccount()], 'item' => [], 'request_id' => 'req-balance'])]);

    app(RefreshLiveBalances::class)->handle($item, ['acc-checking']);

    expect(PlaidLog::sole())
        ->plaid_item_id->toBe($item->id)
        ->kind->toBe(PlaidLogKind::LiveBalance)
        ->triggered_by->toBe(PlaidLogTrigger::Manual)
        ->result->toBe(PlaidLogResult::Succeeded)
        ->pages->toBeNull()
        ->requests->toEqual([
            ['endpoint' => '/accounts/balance/get', 'status' => 200, 'request_id' => 'req-balance', 'error_code' => null],
        ]);
});

it('logs a bank link and the sync after it, without the tokens', function () {
    $book = Book::factory()->create();
    Http::fake([
        PLAID.'/link/token/create' => Http::response(['link_token' => 'link-sandbox-123', 'expiration' => '', 'request_id' => 'req-link-token']),
        PLAID.'/item/public_token/exchange' => Http::response(['access_token' => 'access-sandbox-new', 'item_id' => 'item-new', 'request_id' => 'req-exchange']),
        PLAID.'/accounts/get' => Http::response(['accounts' => [plaidAccount()], 'item' => [], 'request_id' => 'req-accounts']),
        PLAID.'/transactions/sync' => Http::response(syncPage()),
    ]);
    $this->actingAs($book->user);

    Livewire::withQueryParams(['book' => $book->id])
        ->test(LinkBank::class)
        ->call('exchange', 'public-sandbox-xyz', 'ins_109508', 'First Platypus Bank');

    $item = PlaidItem::sole();
    [$link, $sync] = PlaidLog::query()->orderBy('id')->get()->all();
    expect($link)
        ->user_id->toBe($book->user_id)
        ->plaid_item_id->toBe($item->id)
        ->institution_name->toBe('First Platypus Bank')
        ->kind->toBe(PlaidLogKind::Link)
        ->result->toBe(PlaidLogResult::Succeeded)
        ->requests->toEqual([
            ['endpoint' => '/item/public_token/exchange', 'status' => 200, 'request_id' => 'req-exchange', 'error_code' => null],
            ['endpoint' => '/accounts/get', 'status' => 200, 'request_id' => 'req-accounts', 'error_code' => null],
        ]);
    expect($sync)
        ->plaid_item_id->toBe($item->id)
        ->kind->toBe(PlaidLogKind::Sync)
        ->triggered_by->toBe(PlaidLogTrigger::Link);
    expect(DB::table('plaid_logs')->get()->toJson())
        ->not->toContain('public-sandbox-xyz')
        ->not->toContain('access-sandbox-new');
});

it('keeps a removed connection’s entries with its bank’s name', function () {
    $item = PlaidItem::factory()->create(['institution_name' => 'First Platypus Bank']);
    PlaidLog::factory()->for($item)->create();
    Http::fake([PLAID.'/item/remove' => Http::response(['request_id' => 'req-remove'])]);

    app(RemovePlaidItem::class)->handle($item);

    expect(PlaidLog::query()->orderBy('id')->get())
        ->toHaveCount(2)
        ->each(fn ($log) => $log
            ->user_id->toBe($item->user_id)
            ->plaid_item_id->toBeNull()
            ->institution_name->toBe('First Platypus Bank'));
    expect(PlaidLog::query()->latest('id')->first())
        ->kind->toBe(PlaidLogKind::Remove)
        ->result->toBe(PlaidLogResult::Succeeded);
});
