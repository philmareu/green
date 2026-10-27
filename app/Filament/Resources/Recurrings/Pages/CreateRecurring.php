<?php

namespace App\Filament\Resources\Recurrings\Pages;

use App\Filament\Resources\Recurrings\RecurringResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurring extends CreateRecord
{
    protected static string $resource = RecurringResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
