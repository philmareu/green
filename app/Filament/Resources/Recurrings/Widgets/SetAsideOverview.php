<?php

namespace App\Filament\Resources\Recurrings\Widgets;

use App\Enums\Frequency;
use App\Enums\RecurringKind;
use App\Models\Recurring;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Number;

/**
 * Quarterly and yearly bills, spread over the months between due dates: what
 * to put aside each month, and what should be aside by now. Display only;
 * the forecast still counts each bill on its due date.
 */
class SetAsideOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Set aside for quarterly and yearly bills';

    public static function canView(): bool
    {
        return static::bills()->isNotEmpty();
    }

    protected function getStats(): array
    {
        $bills = static::bills();
        $count = trans_choice('{1} :count bill|[2,*] :count bills', $bills->count());
        $currency = $bills->first()->account?->currency ?? 'USD';

        return [
            Stat::make('Each month', Number::currency($bills->sum(fn (Recurring $bill): float => $bill->monthlySetAside()), $currency))
                ->description("Across {$count}"),
            Stat::make('By now', Number::currency($bills->sum(fn (Recurring $bill): float => $bill->setAsideByNow() ?? 0.0), $currency))
                ->description('For each bill’s next due date'),
        ];
    }

    /**
     * @return Collection<int, Recurring>
     */
    protected static function bills(): Collection
    {
        return Recurring::query()
            ->ownedBy(auth()->user())
            ->where('kind', RecurringKind::Bill)
            ->whereIn('frequency', [Frequency::EveryThreeMonths, Frequency::Yearly])
            ->with('account', 'occurrences')
            ->get();
    }
}
