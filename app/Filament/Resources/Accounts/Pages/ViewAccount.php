<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Actions\PageActions;
use App\Filament\Actions\PlaidActions;
use App\Filament\Pages\Forecast;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewForecast')
                ->label('View forecast')
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->color('gray')
                ->url(fn (): string => Forecast::getUrl(['filters' => ['account' => $this->getRecord()->getKey()]]))
                ->visible(fn (): bool => ! $this->getRecord()->isCredit() && ! $this->getRecord()->is_hidden),
            PlaidActions::reconnect(),
            PlaidActions::sync()->label('Sync'),
            EditAction::make(),
            PageActions::group([
                PlaidActions::refreshLiveBalance(),
            ]),
        ];
    }
}
