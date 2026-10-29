<?php

namespace App\Filament\Resources\Recurrings\Pages;

use App\Filament\Actions\PageActions;
use App\Filament\Resources\Recurrings\RecurringResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditRecurring extends EditRecord
{
    protected static string $resource = RecurringResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            PageActions::group([
                DeleteAction::make()
                    ->modalDescription('Its due dates are deleted. The transactions stay.'),
            ]),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
