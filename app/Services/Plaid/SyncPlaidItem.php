<?php

namespace App\Services\Plaid;

use App\Enums\PlaidItemStatus;
use App\Models\PlaidItem;
use App\Models\Transaction;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls incremental transaction updates for one Item with /transactions/sync.
 *
 * Each page is written as it arrives; the writes are idempotent (upsert and
 * delete by Plaid id), so replaying pages after a failure is safe. The cursor
 * is saved only once a full pass finishes, as Plaid requires.
 *
 * @see https://plaid.com/docs/api/products/transactions/#transactionssync
 */
class SyncPlaidItem
{
    public const int MAX_RESTARTS = 2;

    public function __construct(
        private readonly PlaidClient $plaid,
        private readonly UpsertAccounts $upsertAccounts,
    ) {}

    public function handle(PlaidItem $item): SyncResult
    {
        try {
            $result = $this->pull($item);
        } catch (PlaidException $e) {
            $this->recordFailure($item, $e);

            return SyncResult::failed($e->userMessage());
        }

        return $result;
    }

    protected function pull(PlaidItem $item): SyncResult
    {
        $startCursor = $item->sync_cursor;
        $restarts = 0;

        while (true) {
            try {
                return $this->pass($item, $startCursor);
            } catch (PlaidException $e) {
                if ($e->errorCode !== 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION' || $restarts >= self::MAX_RESTARTS) {
                    throw $e;
                }

                $restarts++;
            }
        }
    }

    protected function pass(PlaidItem $item, ?string $cursor): SyncResult
    {
        $added = $modified = $removed = 0;
        $status = null;

        do {
            $page = $this->plaid->syncTransactions($item->access_token, $cursor);

            DB::transaction(function () use ($item, $page, &$added, &$modified, &$removed) {
                $this->upsertAccounts->handle($item, $page['accounts'] ?? []);

                $accountIds = $item->accounts()->pluck('id', 'plaid_account_id');

                $added += $this->upsertTransactions($accountIds->all(), $page['added'] ?? []);
                $modified += $this->upsertTransactions($accountIds->all(), $page['modified'] ?? []);
                $removed += $this->deleteSettledPending($accountIds->values()->all(), $page['added'] ?? []);
                $removed += $this->deleteTransactions(
                    $accountIds->values()->all(),
                    Arr::pluck($page['removed'] ?? [], 'transaction_id'),
                );
            });

            $cursor = $page['next_cursor'] ?? $cursor;
            $status = $page['transactions_update_status'] ?? $status;
        } while ($page['has_more'] ?? false);

        $item->forceFill([
            'sync_cursor' => filled($cursor) ? $cursor : null,
            'transactions_update_status' => $status,
            'last_synced_at' => now(),
            'status' => PlaidItemStatus::Active,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        return new SyncResult(true, $added, $modified, $removed);
    }

    /**
     * @param  array<string, int>  $accountIds  Plaid account id => local account id
     * @param  list<array<string, mixed>>  $transactions
     */
    protected function upsertTransactions(array $accountIds, array $transactions): int
    {
        $rows = [];

        foreach ($transactions as $transaction) {
            $accountId = $accountIds[$transaction['account_id']] ?? null;

            if ($accountId === null) {
                Log::warning('Plaid transaction references an unknown account; skipping.', [
                    'transaction_id' => $transaction['transaction_id'],
                    'account_id' => $transaction['account_id'],
                ]);

                continue;
            }

            $rows[] = [
                'account_id' => $accountId,
                'plaid_transaction_id' => $transaction['transaction_id'],
                'pending_plaid_transaction_id' => $transaction['pending_transaction_id'] ?? null,
                'amount' => $transaction['amount'],
                'currency' => $transaction['iso_currency_code'] ?? $transaction['unofficial_currency_code'] ?? 'USD',
                'date' => $transaction['date'],
                'authorized_date' => $transaction['authorized_date'] ?? null,
                'name' => $transaction['name'] ?? $transaction['merchant_name'] ?? 'Transaction',
                'merchant_name' => $transaction['merchant_name'] ?? null,
                'pending' => (bool) ($transaction['pending'] ?? false),
                'category_primary' => $transaction['personal_finance_category']['primary'] ?? null,
                'category_detailed' => $transaction['personal_finance_category']['detailed'] ?? null,
                'payment_channel' => $transaction['payment_channel'] ?? null,
                'raw' => json_encode($transaction),
            ];
        }

        if ($rows === []) {
            return 0;
        }

        Transaction::query()->upsert(
            $rows,
            uniqueBy: ['plaid_transaction_id'],
            update: array_values(array_diff(array_keys($rows[0]), ['plaid_transaction_id'])),
        );

        return count($rows);
    }

    /**
     * When a pending transaction posts, Plaid adds a new posted transaction
     * pointing at the pending one, but the pending one's removal can arrive in
     * a later sync. Delete it now so the money isn't counted twice.
     *
     * @param  list<int>  $accountIds
     * @param  list<array<string, mixed>>  $added
     */
    protected function deleteSettledPending(array $accountIds, array $added): int
    {
        $pendingIds = collect($added)
            ->reject(fn (array $transaction) => $transaction['pending'] ?? false)
            ->pluck('pending_transaction_id')
            ->filter()
            ->values()
            ->all();

        return $this->deleteTransactions($accountIds, $pendingIds, onlyPending: true);
    }

    /**
     * @param  list<int>  $accountIds
     * @param  list<string>  $plaidTransactionIds
     */
    protected function deleteTransactions(array $accountIds, array $plaidTransactionIds, bool $onlyPending = false): int
    {
        if ($plaidTransactionIds === [] || $accountIds === []) {
            return 0;
        }

        return Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->whereIn('plaid_transaction_id', $plaidTransactionIds)
            ->when($onlyPending, fn ($query) => $query->where('pending', true))
            ->delete();
    }

    protected function recordFailure(PlaidItem $item, PlaidException $e): void
    {
        $item->forceFill([
            'status' => $e->requiresLogin() ? PlaidItemStatus::LoginRequired : PlaidItemStatus::Error,
            'error_code' => $e->errorCode,
            'error_message' => $e->userMessage(),
        ])->save();

        Log::warning('Plaid sync failed.', [
            'plaid_item_id' => $item->plaid_item_id,
            'error_code' => $e->errorCode,
            'request_id' => $e->requestId,
        ]);
    }
}
