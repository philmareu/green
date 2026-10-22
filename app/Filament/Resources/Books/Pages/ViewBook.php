<?php

namespace App\Filament\Resources\Books\Pages;

use App\Filament\Actions\PlaidActions;
use App\Filament\Pages\LinkBank;
use App\Filament\Resources\Books\BookResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewBook extends ViewRecord
{
    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('linkBank')
                ->label('Link bank account')
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => LinkBank::getUrl(['book' => $this->getRecord()->getKey()])),
            PlaidActions::sync()->label('Sync'),
            EditAction::make(),
        ];
    }
}
