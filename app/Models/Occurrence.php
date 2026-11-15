<?php

namespace App\Models;

use App\Enums\MatchSource;
use App\Enums\OccurrenceResolution;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Services\Categories\CategorizeTransactions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One due date of a recurring item, or one period of a flex spend. A bill or
 * income due date is paid by the linked transaction, or closed by hand with a
 * resolution (paid some other way, or skipped). A bill's or income's due date
 * can be postponed: `due_on` stays the scheduled date, and `postponed_to` is
 * when it's expected instead (`expectedOn()`).
 */
#[Fillable(['recurring_id', 'due_on', 'postponed_to', 'transaction_id', 'matched_by', 'resolution'])]
class Occurrence extends Model
{
    /** @use HasFactory<OccurrenceFactory> */
    use HasFactory;

    /**
     * A transaction that pays a bill or income takes its category, so
     * recategorize transactions as they're linked and unlinked.
     */
    protected static function booted(): void
    {
        static::saved(function (Occurrence $occurrence): void {
            if ($occurrence->wasRecentlyCreated || $occurrence->wasChanged('transaction_id')) {
                static::categorize([$occurrence->transaction_id, $occurrence->getPrevious()['transaction_id'] ?? null]);
            }
        });

        static::deleted(fn (Occurrence $occurrence) => static::categorize([$occurrence->transaction_id]));
    }

    /**
     * @param  list<?int>  $transactionIds
     */
    protected static function categorize(array $transactionIds): void
    {
        $transactionIds = array_values(array_filter($transactionIds));

        if ($transactionIds !== []) {
            app(CategorizeTransactions::class)->handle(Transaction::query()->whereKey($transactionIds));
        }
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'postponed_to' => 'immutable_date',
            'matched_by' => MatchSource::class,
            'resolution' => OccurrenceResolution::class,
        ];
    }

    /** @return BelongsTo<Recurring, $this> */
    public function recurring(): BelongsTo
    {
        return $this->belongsTo(Recurring::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * When it's expected: the postponed date, else the due date.
     */
    public function expectedOn(): CarbonImmutable
    {
        return $this->postponed_to ?? $this->due_on;
    }

    public function isOpen(): bool
    {
        return $this->transaction_id === null && $this->resolution === null;
    }

    public function status(): OccurrenceStatus
    {
        $today = today();

        if ($this->resolution === OccurrenceResolution::Skipped) {
            return OccurrenceStatus::Skipped;
        }

        if ($this->recurring->kind === RecurringKind::Flex) {
            if ($this->due_on->gt($today)) {
                return OccurrenceStatus::Upcoming;
            }

            return $this->periodEnd()?->lte($today) ? OccurrenceStatus::Ended : OccurrenceStatus::Current;
        }

        if ($this->transaction_id !== null && $this->transaction !== null) {
            return $this->transaction->pending ? OccurrenceStatus::Pending : OccurrenceStatus::Paid;
        }

        if ($this->resolution === OccurrenceResolution::Paid) {
            return OccurrenceStatus::MarkedPaid;
        }

        if ($this->expectedOn()->lt($today)) {
            return OccurrenceStatus::Overdue;
        }

        return $this->postponed_to !== null ? OccurrenceStatus::Postponed : OccurrenceStatus::Upcoming;
    }

    /**
     * Where a flex spend's period ends (the next period's start).
     */
    public function periodEnd(): ?CarbonImmutable
    {
        return $this->recurring->frequency->after($this->recurring->starts_on, $this->due_on);
    }

    public function spent(): float
    {
        return $this->recurring->spentBetween($this->due_on, $this->periodEnd() ?? $this->due_on->addDay());
    }

    /**
     * What a flex spend has left this period.
     */
    public function left(): float
    {
        return max(0.0, round((float) $this->recurring->amount - $this->spent(), 2));
    }

    /**
     * Transactions you could link by hand: on the item's account, going the
     * right way, within 30 days of when it's expected, and not paying anything
     * else.
     *
     * @return Collection<int, Transaction>
     */
    public function candidateTransactions(): Collection
    {
        $recurring = $this->recurring;

        if ($recurring->account_id === null) {
            return collect();
        }

        $expectedOn = $this->expectedOn();

        return Transaction::query()
            ->where('account_id', $recurring->account_id)
            ->where('amount', $recurring->kind->isOutflow() ? '>' : '<', 0)
            ->whereDoesntHave('occurrence')
            ->whereRaw('date(coalesce(authorized_date, date)) >= ?', [$expectedOn->subDays(30)->toDateString()])
            ->whereRaw('date(coalesce(authorized_date, date)) <= ?', [$expectedOn->addDays(30)->toDateString()])
            ->orderByDesc('date')
            ->get();
    }

    public function link(Transaction $transaction, MatchSource $matchedBy): void
    {
        if ($matchedBy === MatchSource::Manual) {
            $this->recurring->undismiss($transaction);
        }

        $this->update(['transaction_id' => $transaction->getKey(), 'matched_by' => $matchedBy, 'resolution' => null]);
    }

    /**
     * Unlink the paying transaction and remember not to link it again.
     */
    public function unlink(): void
    {
        if ($this->transaction !== null) {
            $this->recurring->dismiss($this->transaction);
        }

        $this->update(['transaction_id' => null, 'matched_by' => null]);
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('transaction_id')->whereNull('resolution');
    }

    /**
     * Only due dates expected on or before `$date` (postponed ones by their
     * new date).
     */
    #[Scope]
    protected function expectedBy(Builder $query, CarbonInterface $date): void
    {
        $query->whereRaw('date(coalesce(postponed_to, due_on)) <= ?', [$date->toDateString()]);
    }

    /**
     * The due dates `status()` calls overdue: a bill's or income's, unpaid,
     * expected before today. A flex period is never overdue.
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->open()
            ->whereRaw('date(coalesce(postponed_to, due_on)) < ?', [today()->toDateString()])
            ->whereHas('recurring', fn (Builder $recurring) => $recurring->where('kind', '!=', RecurringKind::Flex));
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->whereHas('recurring', fn (Builder $recurring) => $recurring->where('user_id', $user->getKey()));
    }
}
