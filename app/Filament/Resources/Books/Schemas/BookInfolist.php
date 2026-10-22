<?php

namespace App\Filament\Resources\Books\Schemas;

use App\Filament\Resources\CategorySets\CategorySetResource;
use App\Models\Book;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class BookInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(['default' => 2, 'md' => 5])
                    ->schema([
                        TextEntry::make('kind')->badge(),
                        TextEntry::make('cash_available')
                            ->label('Cash available')
                            ->state(fn (Book $record): string => Number::currency($record->cashAvailable(), $record->currency()))
                            ->helperText('Available balance, after pending activity')
                            ->weight('bold'),
                        TextEntry::make('credit_owed')
                            ->label('Credit owed')
                            ->state(fn (Book $record): string => Number::currency($record->creditOwed(), $record->currency())),
                        TextEntry::make('pending_count')
                            ->label('Pending transactions')
                            ->state(fn (Book $record): int => $record->transactions()->where('pending', true)->count()),
                        TextEntry::make('categorySet.name')
                            ->label('Categories')
                            ->placeholder('None')
                            ->url(fn (Book $record): ?string => $record->categorySet
                                ? CategorySetResource::getUrl('view', ['record' => $record->categorySet])
                                : null),
                    ]),
            ]);
    }
}
