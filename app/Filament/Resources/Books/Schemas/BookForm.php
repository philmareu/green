<?php

namespace App\Filament\Resources\Books\Schemas;

use App\Enums\BookKind;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;

class BookForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->placeholder('e.g. Acme Consulting LLC')
                    ->required()
                    ->maxLength(255),
                ToggleButtons::make('kind')
                    ->options(BookKind::class)
                    ->default(BookKind::Business)
                    ->inline()
                    ->required(),
            ]);
    }
}
