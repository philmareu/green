<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

/**
 * How often a recurring item is due. Each due date is counted from the first
 * one, so a bill due on the 31st falls on the last day of shorter months and
 * returns to the 31st afterwards.
 */
enum Frequency: string implements HasLabel
{
    case Once = 'once';
    case Weekly = 'weekly';
    case EveryTwoWeeks = 'every_two_weeks';
    case Monthly = 'monthly';
    case EveryThreeMonths = 'every_three_months';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Once => 'One time',
            self::Weekly => 'Weekly',
            self::EveryTwoWeeks => 'Every 2 weeks',
            self::Monthly => 'Monthly',
            self::EveryThreeMonths => 'Every 3 months',
            self::Yearly => 'Yearly',
        };
    }

    /**
     * The period in "$89.00 / month"; null for one-time items.
     */
    public function unit(): ?string
    {
        return match ($this) {
            self::Once => null,
            self::Weekly => 'week',
            self::EveryTwoWeeks => '2 weeks',
            self::Monthly => 'month',
            self::EveryThreeMonths => '3 months',
            self::Yearly => 'year',
        };
    }

    /**
     * How many days before or after a due date a payment still counts for it.
     * Kept under half a period so neighbouring due dates don't compete.
     */
    public function matchWindowDays(): int
    {
        return match ($this) {
            self::Weekly => 3,
            self::EveryTwoWeeks => 5,
            default => 7,
        };
    }

    public function nth(CarbonImmutable $start, int $n): CarbonImmutable
    {
        return match ($this) {
            self::Once => $start,
            self::Weekly => $start->addWeeks($n),
            self::EveryTwoWeeks => $start->addWeeks(2 * $n),
            self::Monthly => $start->addMonthsNoOverflow($n),
            self::EveryThreeMonths => $start->addMonthsNoOverflow(3 * $n),
            self::Yearly => $start->addYearsNoOverflow($n),
        };
    }

    /**
     * Due dates from `$from` through `$until`, stopping at `$endsOn`.
     *
     * @return list<CarbonImmutable>
     */
    public function dueDates(CarbonImmutable $start, CarbonInterface $from, CarbonInterface $until, ?CarbonInterface $endsOn = null): array
    {
        $last = $endsOn !== null && $endsOn->lt($until) ? $endsOn : $until;
        $dates = [];

        for ($n = 0; ($date = $this->nth($start, $n))->lte($last); $n++) {
            if ($date->gte($from)) {
                $dates[] = $date;
            }

            if ($this === self::Once) {
                break;
            }
        }

        return $dates;
    }

    /**
     * The first due date after `$date`, which ends a flex spend's period.
     */
    public function after(CarbonImmutable $start, CarbonInterface $date): ?CarbonImmutable
    {
        if ($this === self::Once) {
            return null;
        }

        for ($n = 0; ($next = $this->nth($start, $n))->lte($date); $n++) {
            //
        }

        return $next;
    }
}
