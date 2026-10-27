<?php

namespace App\Services\Recurring;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * An account's expected balance from today through `until`.
 */
final readonly class Forecast
{
    /**
     * @param  list<ForecastEntry>  $entries  in date order, each with the balance after it
     */
    public function __construct(
        public float $starting,
        public array $entries,
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public string $currency,
    ) {}

    public function ending(): float
    {
        return $this->entries === [] ? $this->starting : $this->entries[array_key_last($this->entries)]->balance;
    }

    /**
     * The lowest the balance gets, and the first day it gets there.
     *
     * @return array{balance: float, date: CarbonImmutable}
     */
    public function lowest(): array
    {
        $lowest = ['balance' => $this->starting, 'date' => $this->from];

        foreach ($this->entries as $entry) {
            if ($entry->balance < $lowest['balance']) {
                $lowest = ['balance' => $entry->balance, 'date' => $entry->date];
            }
        }

        return $lowest;
    }

    /**
     * The balance at the end of each day.
     *
     * @return array<string, float> Y-m-d => balance
     */
    public function daily(): array
    {
        $byDate = collect($this->entries)->mapWithKeys(fn (ForecastEntry $entry): array => [$entry->date->toDateString() => $entry->balance]);
        $balance = $this->starting;
        $daily = [];

        foreach (CarbonPeriod::create($this->from, $this->until) as $day) {
            $balance = $byDate[$day->toDateString()] ?? $balance;
            $daily[$day->toDateString()] = $balance;
        }

        return $daily;
    }
}
