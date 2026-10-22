<?php

namespace App\Filament\Resources\CategorySets\Pages;

use App\Filament\Resources\CategorySets\CategorySetResource;
use App\Models\CategorySet;
use App\Services\Categories\StarterCategories;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateCategorySet extends CreateRecord
{
    protected static string $resource = CategorySetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $withStarterCategories = (bool) Arr::pull($data, 'with_starter_categories', false);

        /** @var CategorySet $set */
        $set = parent::handleRecordCreation($data);

        if ($withStarterCategories) {
            app(StarterCategories::class)->addTo($set);
        }

        return $set;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
