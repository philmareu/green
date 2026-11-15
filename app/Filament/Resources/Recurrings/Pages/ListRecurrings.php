<?php

namespace App\Filament\Resources\Recurrings\Pages;

use App\Filament\Resources\Recurrings\RecurringResource;
use App\Filament\Resources\Recurrings\Widgets\SetAsideOverview;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecurrings extends ListRecords
{
    protected static string $resource = RecurringResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            SetAsideOverview::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New recurring item'),
        ];
    }
}
