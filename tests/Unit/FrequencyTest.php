<?php

use App\Enums\Frequency;
use Carbon\CarbonImmutable;

it('keeps a monthly bill on its day, using the last day of shorter months', function () {
    $dates = Frequency::Monthly->dueDates(CarbonImmutable::parse('2026-01-31'), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-04-30'));

    expect(array_map(fn (CarbonImmutable $date) => $date->toDateString(), $dates))
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

it('stops at the end date and returns a one-time date once', function () {
    $start = CarbonImmutable::parse('2026-10-01');

    expect(Frequency::Weekly->dueDates($start, $start, $start->addMonths(2), endsOn: $start->addDays(14)))->toHaveCount(3)
        ->and(Frequency::Once->dueDates($start, $start, $start->addYear()))->toHaveCount(1)
        ->and(Frequency::Once->dueDates($start, $start->addDay(), $start->addYear()))->toBeEmpty();
});
