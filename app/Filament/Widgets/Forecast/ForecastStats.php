<?php

namespace App\Filament\Widgets\Forecast;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Widgets\Forecast\Concerns\InteractsWithForecast;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

class ForecastStats extends StatsOverviewWidget
{
    use InteractsWithForecast;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    #[On(self::UPDATED_EVENT)]
    public function refreshForecast(): void
    {
        //
    }

    protected function getStats(): array
    {
        $account = $this->forecastAccount();
        $forecast = $this->forecast();

        if ($account === null || $forecast === null) {
            return [
                Stat::make('No cash accounts', 'Link a bank')
                    ->description('The forecast projects a checking or savings account.')
                    ->url(AccountResource::getUrl()),
            ];
        }

        $money = fn (float $amount): string => Number::currency($amount, $forecast->currency);
        $lowest = $forecast->lowest();
        $change = round($forecast->ending() - $forecast->starting, 2);
        $weeks = $this->forecastWeeks();

        return [
            Stat::make('Available today', $money($forecast->starting))
                ->description($account->display_name)
                ->url(AccountResource::getUrl('view', ['record' => $account])),
            Stat::make('Lowest', $money($lowest['balance']))
                ->description($lowest['date']->isToday() ? 'Today' : $lowest['date']->format('D, M j'))
                ->color($lowest['balance'] < 0 ? 'danger' : null),
            Stat::make("In {$weeks} weeks", $money($forecast->ending()))
                ->description(($change >= 0 ? '+' : '−').$money(abs($change)).' from today')
                ->descriptionIcon($change >= 0 ? Heroicon::OutlinedArrowTrendingUp : Heroicon::OutlinedArrowTrendingDown)
                ->color($change >= 0 ? 'success' : 'danger'),
        ];
    }
}
