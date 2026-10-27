<?php

namespace App\Filament\Widgets\Forecast;

use App\Filament\Widgets\Forecast\Concerns\InteractsWithForecast;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\On;

class ForecastChart extends ChartWidget
{
    use InteractsWithForecast;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Balance';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    #[On(self::UPDATED_EVENT)]
    public function refreshForecast(): void
    {
        //
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $daily = $this->forecast()?->daily() ?? [];

        return [
            'datasets' => [
                [
                    'label' => 'Balance',
                    'data' => array_values($daily),
                    'stepped' => true,
                    'pointRadius' => 0,
                ],
            ],
            'labels' => array_map(fn (string $date): string => CarbonImmutable::parse($date)->format('M j'), array_keys($daily)),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['ticks' => ['maxTicksLimit' => 8, 'maxRotation' => 0]]],
        ];
    }
}
