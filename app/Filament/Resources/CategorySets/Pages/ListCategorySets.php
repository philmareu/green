<?php

namespace App\Filament\Resources\CategorySets\Pages;

use App\Filament\Resources\CategorySets\CategorySetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCategorySets extends ListRecords
{
    protected static string $resource = CategorySetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New category set'),
        ];
    }
}
