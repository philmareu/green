<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Forecast\ForecastChart;
use App\Filament\Widgets\Forecast\ForecastRegister;
use App\Filament\Widgets\Forecast\ForecastStats;
use App\Models\Account;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * An account's balance over the next few weeks: today's available balance,
 * then each expected bill, flex budget and income.
 */
class Forecast extends Dashboard
{
    use HasFiltersForm {
        mountHasFilters as mountFilters;
    }

    protected static string $routePath = 'forecast';

    protected static ?string $title = 'Forecast';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 0;

    /**
     * A link can name just the account (`?filters[account]=`, from an
     * account's page); the weeks then stay as last chosen.
     */
    public function mountHasFilters(): void
    {
        if ($this->filters) {
            $this->filters = [
                'weeks' => data_get(session($this->getFiltersSessionKey()), 'weeks') ?? ForecastStats::DEFAULT_WEEKS,
                ...$this->filters,
            ];
        }

        $this->mountFilters();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(['md' => 2])
            ->components([
                Select::make('account')
                    ->options(fn (): array => ForecastStats::forecastAccounts()
                        ->mapWithKeys(fn (Account $account): array => [$account->getKey() => $account->display_name])
                        ->all())
                    ->default(fn (): ?int => ForecastStats::forecastAccounts()->first()?->getKey())
                    ->selectablePlaceholder(false),
                Select::make('weeks')
                    ->options(collect(ForecastStats::WEEKS)->mapWithKeys(fn (int $weeks): array => [$weeks => "{$weeks} weeks"])->all())
                    ->default(ForecastStats::DEFAULT_WEEKS)
                    ->selectablePlaceholder(false),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            ForecastStats::class,
            ForecastChart::class,
            ForecastRegister::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
