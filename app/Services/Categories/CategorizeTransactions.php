<?php

namespace App\Services\Categories;

use App\Enums\CategorySource;
use App\Enums\RecurringKind;
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
 * - A category you picked yourself is otherwise left alone.
 * - Other transactions that pay a bill or income with a category get that
 *   category.
 * - Next comes a category with a payee the description contains; the longest
 *   payee wins.
 * - The rest get the category whose Plaid categories match: a detailed match
 *   beats a primary one.
 */
class CategorizeTransactions
{
    /** @var array<int, array{ids: array<int, true>, byPlaidCategory: array<string, int>, payees: list<array{0: string, 1: int}>}> */
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
            ->select($transactions->qualifyColumns(['id', 'account_id', 'name', 'merchant_name', 'category_id', 'category_source', 'category_primary', 'category_detailed']))
            ->with(['account:id,book_id', 'account.book:id,category_set_id', 'occurrence:id,recurring_id,transaction_id', 'occurrence.recurring:id,kind,category_id'])
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

        if ($categoryId !== null && ! $source?->isAutomatic()) {
            return [$categoryId, $source];
        }

        if ($set === null) {
            return [null, null];
        }

        $fromRecurring = $this->fromRecurring($set['ids'], $transaction);

        if ($fromRecurring !== null) {
            return [$fromRecurring, CategorySource::Recurring];
        }

        $fromPayee = $this->fromPayee($set['payees'], $transaction);

        if ($fromPayee !== null) {
            return [$fromPayee, CategorySource::Payee];
        }

        $suggested = $this->suggest($set['byPlaidCategory'], $transaction);

        return [$suggested, $suggested === null ? null : CategorySource::Suggested];
    }

    /**
     * The category of the bill or income this transaction pays, when it's in
     * the transaction's set. A flex spend's category is its budget, not one
     * to give the transactions linked to it.
     *
     * @param  array<int, true>  $ids
     */
    protected function fromRecurring(array $ids, Transaction $transaction): ?int
    {
        $recurring = $transaction->occurrence?->recurring;

        if ($recurring === null || $recurring->kind === RecurringKind::Flex || $recurring->category_id === null) {
            return null;
        }

        return isset($ids[$recurring->category_id]) ? $recurring->category_id : null;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $payees  payee and category, longest payee first
     */
    protected function fromPayee(array $payees, Transaction $transaction): ?int
    {
        foreach ($payees as [$payee, $categoryId]) {
            if ($transaction->descriptionContains($payee)) {
                return $categoryId;
            }
        }

        return null;
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
     * @return array{ids: array<int, true>, byPlaidCategory: array<string, int>, payees: list<array{0: string, 1: int}>}
     */
    protected function set(int $setId): array
    {
        if (isset($this->sets[$setId])) {
            return $this->sets[$setId];
        }

        $categories = Category::query()->where('category_set_id', $setId)->orderBy('id')->get(['id', 'plaid_categories', 'payees']);
        $byPlaidCategory = $payees = [];

        foreach ($categories as $category) {
            foreach ($category->plaid_categories ?? [] as $code) {
                $byPlaidCategory[$code] ??= $category->getKey();
            }

            foreach ($category->payees ?? [] as $payee) {
                $payees[] = [$payee, $category->getKey()];
            }
        }

        usort($payees, fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return $this->sets[$setId] = [
            'ids' => $categories->mapWithKeys(fn (Category $category): array => [$category->getKey() => true])->all(),
            'byPlaidCategory' => $byPlaidCategory,
            'payees' => $payees,
        ];
    }
}
