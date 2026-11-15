<?php

namespace App\Services\Recurring;

use App\Enums\MatchSource;
use App\Enums\RecurringKind;
use App\Models\Occurrence;
use App\Models\Recurring;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links bills and income to the transactions that paid them.
 *
 * A transaction pays a due date when it's on the item's account, goes the right
 * way, its description contains the item's payee, its amount is within the
 * item's tolerance, and its date is within the frequency's match window of
 * when the due date is expected (its new date, when postponed). A matching
 * amount alone is never enough. Links you made by hand are kept, and
 * transactions you unlinked are never linked again.
 */
class MatchRecurringTransactions
{
    /**
     * Match every item on these accounts; runs after each sync.
     *
     * @param  iterable<int>  $accountIds
     */
    public function forAccounts(iterable $accountIds): int
    {
        return DB::transaction(function () use ($accountIds): int {
            $recurrings = Recurring::query()->whereIn('account_id', collect($accountIds)->all())->get();

            $recurrings->each(fn (Recurring $recurring) => $recurring->syncOccurrences());

            return $this->match($recurrings);
        });
    }

    /**
     * After an item is added or its matching rules change: re-check its
     * automatic links and look for payments, including ones made before it
     * was added.
     */
    public function forRecurring(Recurring $recurring): int
    {
        return DB::transaction(function () use ($recurring): int {
            $recurring->syncOccurrences(withHistory: true);
            $this->releaseStaleLinks($recurring);

            $matched = $recurring->account_id === null
                ? 0
                : $this->match(Recurring::query()->where('account_id', $recurring->account_id)->get());

            $recurring->pruneUntracked();

            return $matched;
        });
    }

    /**
     * Keep an automatic link while its transaction still has the item's
     * account, direction and payee. The amount isn't checked again, so a price
     * change doesn't unlink earlier payments.
     */
    protected function releaseStaleLinks(Recurring $recurring): void
    {
        $recurring->occurrences()
            ->where('matched_by', MatchSource::Auto)
            ->whereNotNull('transaction_id')
            ->with('transaction')
            ->get()
            ->reject(fn (Occurrence $occurrence): bool => $occurrence->transaction->account_id === $recurring->account_id
                && $recurring->matches($occurrence->transaction, checkAmount: false))
            ->each(fn (Occurrence $occurrence) => $occurrence->update(['transaction_id' => null, 'matched_by' => null]));
    }

    /**
     * @param  Collection<int, Recurring>  $recurrings
     */
    protected function match(Collection $recurrings): int
    {
        return $recurrings
            ->filter(fn (Recurring $recurring): bool => $recurring->account_id !== null
                && $recurring->kind !== RecurringKind::Flex
                && filled($recurring->payee))
            ->groupBy('account_id')
            ->map(fn (Collection $items, int $accountId): int => $this->matchAccount($accountId, $items->keyBy('id')))
            ->sum();
    }

    /**
     * Pair one account's open due dates with its unlinked transactions, closest
     * date first, then closest amount, then the most specific payee, using
     * each due date and each transaction once.
     *
     * @param  Collection<int, Recurring>  $recurrings  keyed by id
     */
    protected function matchAccount(int $accountId, Collection $recurrings): int
    {
        $widestWindow = $recurrings->max(fn (Recurring $recurring): int => $recurring->frequency->matchWindowDays());

        $occurrences = Occurrence::query()
            ->whereIn('recurring_id', $recurrings->keys())
            ->open()
            ->expectedBy(today()->addDays($widestWindow))
            ->get();

        if ($occurrences->isEmpty()) {
            return 0;
        }

        $expectedOn = $occurrences->map(fn (Occurrence $occurrence): CarbonImmutable => $occurrence->expectedOn());

        $transactions = Transaction::query()
            ->where('account_id', $accountId)
            ->whereDoesntHave('occurrence')
            ->whereRaw('date(coalesce(authorized_date, date)) >= ?', [$expectedOn->min()->subDays($widestWindow)->toDateString()])
            ->whereRaw('date(coalesce(authorized_date, date)) <= ?', [$expectedOn->max()->addDays($widestWindow)->toDateString()])
            ->get();

        $pairs = [];

        foreach ($occurrences as $occurrence) {
            $recurring = $recurrings[$occurrence->recurring_id];

            foreach ($transactions as $transaction) {
                $days = (int) abs($transaction->display_date->startOfDay()->diffInDays($occurrence->expectedOn()));

                if ($days > $recurring->frequency->matchWindowDays() || ! $recurring->matches($transaction)) {
                    continue;
                }

                $pairs[] = [
                    'score' => [$days, abs(abs((float) $transaction->amount) - (float) $recurring->amount), -mb_strlen($recurring->payee)],
                    'occurrence' => $occurrence,
                    'transaction' => $transaction,
                ];
            }
        }

        usort($pairs, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        $usedOccurrences = $usedTransactions = [];

        foreach ($pairs as ['occurrence' => $occurrence, 'transaction' => $transaction]) {
            if (isset($usedOccurrences[$occurrence->id]) || isset($usedTransactions[$transaction->id])) {
                continue;
            }

            $occurrence->update(['transaction_id' => $transaction->id, 'matched_by' => MatchSource::Auto]);
            $usedOccurrences[$occurrence->id] = $usedTransactions[$transaction->id] = true;
        }

        return count($usedOccurrences);
    }
}
