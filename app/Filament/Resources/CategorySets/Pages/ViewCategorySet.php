<?php

namespace App\Filament\Resources\CategorySets\Pages;

use App\Filament\Resources\CategorySets\CategorySetResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCategorySet extends ViewRecord
{
    protected static string $resource = CategorySetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
