<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Models\Book;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->helperText('Shown throughout Green. Syncing never overwrites it.')
                    ->required()
                    ->maxLength(255),
                Select::make('book_id')
                    ->label('Book')
                    ->options(fn (): array => Book::query()->ownedBy(auth()->user())->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('Unassigned')
                    ->helperText('Accounts from one bank login can live in different Books.'),
                Toggle::make('is_hidden')
                    ->label('Hide this account')
                    ->helperText('Hidden accounts are left out of lists and totals but keep syncing.'),
            ]);
    }
}
