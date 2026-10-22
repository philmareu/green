<?php

namespace App\Filament\Resources\PlaidItems\Pages;

use App\Filament\Pages\LinkBank;
use App\Filament\Resources\PlaidItems\PlaidItemResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPlaidItems extends ListRecords
{
    protected static string $resource = PlaidItemResource::class;

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
