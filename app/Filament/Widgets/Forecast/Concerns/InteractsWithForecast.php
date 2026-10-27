<?php

namespace App\Filament\Widgets\Forecast\Concerns;

use App\Models\Account;
use App\Services\Recurring\Forecast;
use App\Services\Recurring\ForecastBalance;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads the Forecast page's filters. They come from the URL or the session, so
 * they can be stale or point at someone else's account: anything that isn't
 * one of your visible cash accounts falls back to your first one.
 */
trait InteractsWithForecast
{
    use InteractsWithPageFilters;

    public const array WEEKS = [2, 4, 6, 8, 12];

    public const int DEFAULT_WEEKS = 6;

    /**
     * Dispatched after a change on the Forecast page so its widgets re-render.
     */
    public const string UPDATED_EVENT = 'forecast-updated';

    protected ?Forecast $cachedForecast = null;

    /**
     * @return Collection<int, Account>
     */
    public static function forecastAccounts(): Collection
    {
        return Account::query()->ownedBy(auth()->user())->visible()->cash()->orderBy('name')->get();
    }

    protected function forecastAccount(): ?Account
    {
        $accounts = static::forecastAccounts();

        return $accounts->find($this->pageFilters['account'] ?? null) ?? $accounts->first();
    }

    protected function forecastWeeks(): int
    {
        $weeks = (int) ($this->pageFilters['weeks'] ?? 0);

        return in_array($weeks, self::WEEKS, true) ? $weeks : self::DEFAULT_WEEKS;
    }

    protected function forecast(): ?Forecast
    {
        if ($this->cachedForecast !== null) {
            return $this->cachedForecast;
        }

        $account = $this->forecastAccount();

        return $this->cachedForecast = $account === null
            ? null
            : app(ForecastBalance::class)->handle($account, today()->addWeeks($this->forecastWeeks()));
    }
}
