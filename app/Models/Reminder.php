<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A heads-up `days_before` a bill's or income's due date, such as "cancel
 * Netflix before it renews". It follows the due date: none for one that's
 * paid or skipped, and a postponed one is timed from its new date. A one-off
 * reminder (`repeats` false) is deleted once it's sent; a repeating one
 * remembers the latest date it reminded about in `reminded_through`.
 */
#[Fillable(['recurring_id', 'days_before', 'repeats', 'note', 'reminded_through'])]
class Reminder extends Model
{
    /** @use HasFactory<ReminderFactory> */
    use HasFactory;

    /** The most days ahead a reminder can be set. */
    public const int MAX_DAYS_BEFORE = 60;

    protected function casts(): array
    {
        return [
            'days_before' => 'integer',
            'repeats' => 'boolean',
            'reminded_through' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Recurring, $this> */
    public function recurring(): BelongsTo
    {
        return $this->belongsTo(Recurring::class);
    }

    /**
     * The due date this reminder is for next: the soonest open one expected
     * today or later that it hasn't reminded about yet.
     */
    public function upcomingOccurrence(CarbonImmutable $today): ?Occurrence
    {
        return $this->recurring->occurrences()
            ->open()
            ->get()
            ->filter(fn (Occurrence $occurrence): bool => $occurrence->expectedOn()->gte($today)
                && ($this->reminded_through === null || $occurrence->expectedOn()->gt($this->reminded_through)))
            ->sortBy(fn (Occurrence $occurrence): string => $occurrence->expectedOn()->toDateString())
            ->first();
    }

    /**
     * The upcoming due date, once it's within `days_before` of today.
     */
    public function dueOccurrence(CarbonImmutable $today): ?Occurrence
    {
        $occurrence = $this->upcomingOccurrence($today);

        return $occurrence !== null && $this->remindsOn($occurrence)->lte($today) ? $occurrence : null;
    }

    public function remindsOn(Occurrence $occurrence): CarbonImmutable
    {
        return $occurrence->expectedOn()->subDays($this->days_before);
    }

    /**
     * "3 days before, every due date"
     */
    public function scheduleLabel(): string
    {
        $when = match ($this->days_before) {
            0 => 'On the due date',
            1 => '1 day before',
            default => "{$this->days_before} days before",
        };

        return $when.', '.($this->repeats ? 'every due date' : 'next due date only');
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->whereHas('recurring', fn (Builder $recurring) => $recurring->where('user_id', $user->getKey()));
    }
}
