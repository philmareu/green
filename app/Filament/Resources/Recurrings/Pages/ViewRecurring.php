<?php

namespace App\Filament\Resources\Recurrings\Pages;

use App\Filament\Resources\Recurrings\RecurringResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecurring extends ViewRecord
{
    protected static string $resource = RecurringResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
