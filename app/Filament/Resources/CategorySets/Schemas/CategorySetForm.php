<?php

namespace App\Filament\Resources\CategorySets\Schemas;

use App\Enums\BookKind;
use App\Services\Categories\StarterCategories;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CategorySetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->placeholder('e.g. Business')
                    ->required()
                    ->maxLength(255),
                ToggleButtons::make('kind')
                    ->options(BookKind::class)
                    ->default(BookKind::Business)
                    ->inline()
                    ->required()
                    ->live(),
                Toggle::make('with_starter_categories')
                    ->label('Start with suggested categories')
                    ->helperText(fn (Get $get): ?string => ($kind = $get->enum('kind', BookKind::class, isNullable: true))
                        ? StarterCategories::describe($kind).' You can edit them afterwards.'
                        : null)
                    ->default(true)
                    ->visibleOn('create'),
            ]);
    }
}
