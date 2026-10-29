<?php

namespace App\Filament\Resources\CategorySets\Pages;

use App\Filament\Actions\PageActions;
use App\Filament\Resources\CategorySets\CategorySetResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCategorySet extends EditRecord
{
    protected static string $resource = CategorySetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            PageActions::group([
                DeleteAction::make()
                    ->modalDescription('The set and all of its categories are deleted. Books using it are left without categories.'),
            ]),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
