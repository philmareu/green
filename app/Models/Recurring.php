<?php

namespace App\Models;

use App\Enums\Frequency;
use App\Enums\MatchSource;
use App\Enums\OccurrenceResolution;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Services\Categories\CategorizeTransactions;
use App\Services\Recurring\MatchRecurringTransactions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RecurringFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money you expect to move: a bill, a flex spend (a budget per period, such as
 * groceries) or income. Each due date is an Occurrence.
 *
 * Bills and income are linked to the transaction that paid them only when the
 * transaction's description contains `payee`, so a matching amount alone never
 * counts. `amount` is always positive; `kind` gives the direction. A bill's or
 * income's `category` goes on the transactions linked to it; a flex spend's is
 * the category its budget counts.
 */
#[Fillable([
    'user_id',
    'account_id',
    'kind',
    'name',
    'amount',
    'frequency',
    'starts_on',
    'ends_on',
    'payee',
    'amount_tolerance',
    'category_id',
    'dismissed_transactions',
])]
class Recurring extends Model
{
    /** @use HasFactory<RecurringFactory> */
    use HasFactory;

    /** How far ahead due dates are created. */
    public const int HORIZON_DAYS = 90;

    /** How far back to look for payments made before the item was added. */
    public const int HISTORY_DAYS = 90;

    /** Fields that change which transactions an item matches. */
    public const array MATCH_FIELDS = ['account_id', 'kind', 'amount', 'frequency', 'starts_on', 'ends_on', 'payee', 'amount_tolerance'];

    protected static function booted(): void
    {
        static::saved(function (Recurring $recurring): void {
            if ($recurring->wasRecentlyCreated || $recurring->wasChanged(self::MATCH_FIELDS)) {
                app(MatchRecurringTransactions::class)->forRecurring($recurring);
            }

            if ($recurring->wasChanged(['category_id', 'kind'])) {
                app(CategorizeTransactions::class)->handle(
                    Transaction::query()->whereHas('occurrence', fn (Builder $occurrence) => $occurrence->whereBelongsTo($recurring)),
                );
            }
        });

        // The database cascade skips model events, so unlink payments first to
        // give them back their own category.
        static::deleting(fn (Recurring $recurring) => $recurring->occurrences()->whereNotNull('transaction_id')->get()->each->delete());
    }

    protected function casts(): array
    {
        return [
            'kind' => RecurringKind::class,
            'frequency' => Frequency::class,
            'amount' => 'decimal:4',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'amount_tolerance' => 'integer',
            'dismissed_transactions' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<Occurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class)->orderBy('due_on')->chaperone();
    }

    /**
     * The due date to watch: the unpaid one expected soonest (overdue ones
     * first, postponed ones by their new date) or, for a flex spend, the
     * current period.
     */
    public function nextOccurrence(): ?Occurrence
    {
        $today = today();

        if ($this->kind === RecurringKind::Flex) {
            return $this->occurrences->first(fn (Occurrence $occurrence): bool => $occurrence->status() === OccurrenceStatus::Current)
                ?? $this->occurrences->first(fn (Occurrence $occurrence): bool => $occurrence->due_on->gt($today));
        }

        return $this->occurrences
            ->filter(fn (Occurrence $occurrence): bool => $occurrence->isOpen())
            ->sortBy(fn (Occurrence $occurrence): string => $occurrence->expectedOn()->toDateString())
            ->first();
    }

    public function latestPayment(): ?Occurrence
    {
        return $this->occurrences->filter(fn (Occurrence $occurrence): bool => $occurrence->transaction_id !== null)->last();
    }

    /**
     * Due dates from here on are tracked: they show as overdue when unpaid.
     * Earlier ones are kept only when a payment was found for them.
     */
    public function trackedFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->created_at ?? now())
            ->startOfDay()
            ->subDays($this->frequency->matchWindowDays());
    }

    public function historyFrom(): CarbonImmutable
    {
        return $this->starts_on->max(today()->subDays(self::HISTORY_DAYS)->toImmutable());
    }

    /**
     * Create the due dates through `$until` and drop open ones that an edit
     * took off the schedule. With `$withHistory`, also create past due dates
     * so earlier payments can be found; `pruneUntracked()` removes the ones
     * that stay unpaid.
     */
    public function syncOccurrences(?CarbonInterface $until = null, bool $withHistory = false): void
    {
        $until = CarbonImmutable::parse($until ?? today()->addDays(self::HORIZON_DAYS))->startOfDay();
        $from = $withHistory ? $this->historyFrom()->min($this->trackedFrom()) : $this->trackedFrom();

        $existing = $this->occurrences()->get();

        $scheduled = collect($this->frequency->dueDates(
            $this->starts_on,
            $existing->min('due_on') ?? $from,
            $until->max($existing->max('due_on') ?? $until),
            $this->ends_on,
        ))->map->toDateString()->flip();

        $offSchedule = $existing->filter(fn (Occurrence $occurrence): bool => ! $scheduled->has($occurrence->due_on->toDateString())
            && $occurrence->resolution === null
            && ($occurrence->transaction_id === null || $occurrence->matched_by === MatchSource::Auto));

        $offSchedule->each->delete();

        $taken = $existing->diff($offSchedule)->map(fn (Occurrence $occurrence): string => $occurrence->due_on->toDateString())->flip();

        $missing = collect($this->frequency->dueDates($this->starts_on, $from, $until, $this->ends_on))
            ->reject(fn (CarbonImmutable $date): bool => $taken->has($date->toDateString()))
            ->map(fn (CarbonImmutable $date): array => ['due_on' => $date]);

        $this->occurrences()->createMany($missing->all());
    }

    /**
     * Drop unpaid due dates from before the item was added.
     */
    public function pruneUntracked(): void
    {
        $this->occurrences()->open()->whereDate('due_on', '<', $this->trackedFrom()->toDateString())->delete();
    }

    /**
     * Whether a transaction could be a payment of this item: right direction,
     * description contains the payee, amount within tolerance, and not one you
     * unlinked before. The date is checked against each due date separately.
     */
    public function matches(Transaction $transaction, bool $checkAmount = true): bool
    {
        if ($this->kind === RecurringKind::Flex || blank($this->payee) || $this->isDismissed($transaction)) {
            return false;
        }

        $amount = (float) $transaction->amount;

        if ($this->kind->isOutflow() ? $amount <= 0 : $amount >= 0) {
            return false;
        }

        if (! $transaction->descriptionContains($this->payee)) {
            return false;
        }

        return ! $checkAmount || $this->withinTolerance(abs($amount));
    }

    public function withinTolerance(float $amount): bool
    {
        if ($this->amount_tolerance === null) {
            return true;
        }

        $expected = (float) $this->amount;

        return abs($amount - $expected) <= $expected * $this->amount_tolerance / 100 + 0.005;
    }

    public function isDismissed(Transaction $transaction): bool
    {
        $dismissed = $this->dismissed_transactions ?? [];

        return in_array($transaction->plaid_transaction_id, $dismissed, true)
            || ($transaction->pending_plaid_transaction_id !== null && in_array($transaction->pending_plaid_transaction_id, $dismissed, true));
    }

    /**
     * Never link this transaction automatically again.
     */
    public function dismiss(Transaction $transaction): void
    {
        $this->dismissed_transactions = collect($this->dismissed_transactions ?? [])
            ->push($transaction->plaid_transaction_id)
            ->unique()
            ->values()
            ->all();

        $this->save();
    }

    public function undismiss(Transaction $transaction): void
    {
        if (! $this->isDismissed($transaction)) {
            return;
        }

        $this->dismissed_transactions = collect($this->dismissed_transactions)
            ->reject(fn (string $id): bool => in_array($id, [$transaction->plaid_transaction_id, $transaction->pending_plaid_transaction_id], true))
            ->values()
            ->all();

        $this->save();
    }

    /**
     * A flex spend's spending from `$from` up to (not including) `$until`:
     * transactions on its account in its category or a subcategory, refunds
     * included, leaving out ones that paid a bill.
     */
    public function spentBetween(CarbonInterface $from, CarbonInterface $until): float
    {
        if ($this->account_id === null || $this->category === null) {
            return 0.0;
        }

        return round((float) Transaction::query()
            ->where('account_id', $this->account_id)
            ->whereIn('category_id', $this->category->children()->pluck('id')->push($this->category_id))
            ->whereRaw('date(coalesce(authorized_date, date)) >= ?', [$from->toDateString()])
            ->whereRaw('date(coalesce(authorized_date, date)) < ?', [$until->toDateString()])
            ->whereDoesntHave('occurrence')
            ->sum('amount'), 2);
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }

    /**
     * Items with a due date `Occurrence::status()` calls overdue.
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->whereHas('occurrences', fn (Builder $occurrence) => $occurrence->overdue());
    }

    /**
     * Order by the due date `nextOccurrence()` picks, with items that have
     * nothing due last either way, then by name.
     */
    #[Scope]
    protected function orderByNextDue(Builder $query, string $direction = 'asc'): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        [$nextDueOn, $bindings] = $this->nextDueOnSql();

        $query->orderByRaw("({$nextDueOn}) is null", $bindings)
            ->orderByRaw("({$nextDueOn}) {$direction}", $bindings)
            ->orderBy($this->qualifyColumn('name'));
    }

    /**
     * SQL for the date `nextOccurrence()` picks, with its bindings: when a
     * bill's or income's soonest unpaid due date is expected, or a flex spend's
     * current period, else its next one.
     *
     * The current period is the latest one that has started, unless it was
     * skipped or is over. It runs until the next period starts, so it is still
     * running when a later due date exists (they are created in order, so that
     * is the next period), when the item is one-time, or, with no later due
     * date (the item has ended), when it started less than one period ago.
     *
     * @return array{string, list<mixed>}
     */
    protected function nextDueOnSql(): array
    {
        $today = today()->toImmutable();
        $dueDates = fn (string $alias): Builder => Occurrence::query()
            ->from('occurrences', $alias)
            ->whereColumn("{$alias}.recurring_id", $this->qualifyColumn('id'));

        $unpaid = $dueDates('unpaid')
            ->selectRaw('min(date(coalesce(unpaid.postponed_to, unpaid.due_on)))')
            ->where($this->qualifyColumn('kind'), '!=', RecurringKind::Flex)
            ->open();

        $currentPeriod = $dueDates('started')
            ->selectRaw('date(started.due_on)')
            ->where($this->qualifyColumn('kind'), RecurringKind::Flex)
            ->whereDate('started.due_on', '<=', $today->toDateString())
            ->whereNotExists($dueDates('later')
                ->whereRaw('date(later.due_on) > date(started.due_on)')
                ->whereDate('later.due_on', '<=', $today->toDateString()))
            ->where(fn (Builder $started) => $started
                ->whereNull('started.resolution')
                ->orWhere('started.resolution', '!=', OccurrenceResolution::Skipped))
            ->where(function (Builder $running) use ($dueDates, $today): void {
                $running->whereExists($dueDates('later')->whereDate('later.due_on', '>', $today->toDateString()));

                foreach (Frequency::cases() as $frequency) {
                    $running->orWhere(fn (Builder $started) => $started
                        ->where($this->qualifyColumn('frequency'), $frequency)
                        ->when($frequency !== Frequency::Once, fn (Builder $started) => $started
                            ->whereDate('started.due_on', '>', $frequency->nth($today, -1)->toDateString())));
                }
            });

        $nextPeriod = $dueDates('upcoming')
            ->selectRaw('min(date(upcoming.due_on))')
            ->where($this->qualifyColumn('kind'), RecurringKind::Flex)
            ->whereDate('upcoming.due_on', '>', $today->toDateString());

        $subqueries = collect([$unpaid, $currentPeriod, $nextPeriod]);

        return [
            'coalesce('.$subqueries->map(fn (Builder $subquery): string => "({$subquery->toSql()})")->implode(', ').')',
            $subqueries->flatMap(fn (Builder $subquery): array => $subquery->getBindings())->all(),
        ];
    }
}
