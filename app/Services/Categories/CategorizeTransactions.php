<?php

namespace App\Services\Categories;

use App\Enums\CategorySource;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Keeps transaction categories in step with each transaction's Book.
 *
 * - A category that isn't in the Book's category set (the account moved, the
 *   Book changed sets, or the account has no Book) is cleared.
 * - Uncategorized transactions, and ones still on a suggestion, get the
 *   category whose Plaid categories match: a detailed match beats a primary one.
 * - A category you picked yourself is otherwise left alone.
 */
class CategorizeTransactions
{
    /** @var array<int, array{ids: array<int, true>, byPlaidCategory: array<string, int>}> */
    private array $sets = [];

    /**
     * @param  Builder<Transaction>  $transactions
     * @return int How many transactions changed.
     */
    public function handle(Builder $transactions): int
    {
        $this->sets = [];
        $changes = [];

        $transactions->clone()
            ->select($transactions->qualifyColumns(['id', 'account_id', 'category_id', 'category_source', 'category_primary', 'category_detailed']))
            ->with(['account:id,book_id', 'account.book:id,category_set_id'])
            ->chunkById(500, function (Collection $chunk) use (&$changes): void {
                foreach ($chunk as $transaction) {
                    [$categoryId, $source] = $this->resolve($transaction);

                    if ($categoryId !== $transaction->category_id || $source !== $transaction->category_source) {
                        $changes[json_encode([$categoryId, $source?->value])][] = $transaction->getKey();
                    }
                }
            }, $transactions->qualifyColumn('id'), 'id');

        foreach ($changes as $change => $ids) {
            [$categoryId, $source] = json_decode($change);

            foreach (array_chunk($ids, 500) as $batch) {
                Transaction::query()->whereKey($batch)->update(['category_id' => $categoryId, 'category_source' => $source]);
            }
        }

        return array_sum(array_map('count', $changes));
    }

    public function forCategorySet(CategorySet $set): int
    {
        return $this->handle(Transaction::query()->whereHas('account.book', fn (Builder $book) => $book->whereBelongsTo($set)));
    }

    /**
     * @return array{0: ?int, 1: ?CategorySource}
     */
    protected function resolve(Transaction $transaction): array
    {
        $setId = $transaction->account?->book?->category_set_id;
        $set = $setId === null ? null : $this->set($setId);

        $categoryId = $transaction->category_id;
        $source = $transaction->category_source;

        if ($categoryId !== null && ! isset($set['ids'][$categoryId])) {
            [$categoryId, $source] = [null, null];
        }

        if ($categoryId !== null && $source !== CategorySource::Suggested) {
            return [$categoryId, $source];
        }

        $suggested = $set === null ? null : $this->suggest($set['byPlaidCategory'], $transaction);

        return [$suggested, $suggested === null ? null : CategorySource::Suggested];
    }

    /**
     * @param  array<string, int>  $byPlaidCategory
     */
    protected function suggest(array $byPlaidCategory, Transaction $transaction): ?int
    {
        foreach ([$transaction->category_detailed, $transaction->category_primary] as $code) {
            if ($code !== null && isset($byPlaidCategory[$code])) {
                return $byPlaidCategory[$code];
            }
        }

        return null;
    }

    /**
     * @return array{ids: array<int, true>, byPlaidCategory: array<string, int>}
     */
    protected function set(int $setId): array
    {
        if (isset($this->sets[$setId])) {
            return $this->sets[$setId];
        }

        $categories = Category::query()->where('category_set_id', $setId)->orderBy('id')->get(['id', 'plaid_categories']);
        $byPlaidCategory = [];

        foreach ($categories as $category) {
            foreach ($category->plaid_categories ?? [] as $code) {
                $byPlaidCategory[$code] ??= $category->getKey();
            }
        }

        return $this->sets[$setId] = [
            'ids' => $categories->mapWithKeys(fn (Category $category): array => [$category->getKey() => true])->all(),
            'byPlaidCategory' => $byPlaidCategory,
        ];
    }
}
