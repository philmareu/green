<?php

namespace App\Services\Recurring;

use App\Enums\RecurringKind;
use Carbon\CarbonImmutable;

/**
 * One line of the forecast register. `amount` is signed: positive = money in.
 */
final readonly class ForecastEntry
{
    public function __construct(
        public CarbonImmutable $date,
        public string $name,
        public RecurringKind $kind,
        public float $amount,
        public float $balance,
        public int $occurrenceId,
        public int $recurringId,
        public bool $isOneTime,
        public bool $isPostponed = false,
        public ?string $note = null,
    ) {}
}
