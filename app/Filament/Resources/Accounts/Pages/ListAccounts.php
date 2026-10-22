<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Pages\LinkBank;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('linkBank')
                ->label('Link a bank')
                ->icon(Heroicon::OutlinedPlus)
                ->url(LinkBank::getUrl()),
        ];
    }
}
