<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Actions\PageActions;
use App\Filament\Actions\PlaidActions;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlaidActions::reconnect(),
            PlaidActions::sync()->label('Sync'),
            EditAction::make(),
            PageActions::group([
                PlaidActions::refreshLiveBalance(),
            ]),
        ];
    }
}
