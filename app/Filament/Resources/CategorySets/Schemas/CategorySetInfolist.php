<?php

namespace App\Filament\Resources\CategorySets\Schemas;

use App\Filament\Resources\Books\BookResource;
use App\Models\Book;
use App\Models\CategorySet;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategorySetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(['default' => 2, 'md' => 3])
                    ->schema([
                        TextEntry::make('kind')->badge(),
                        TextEntry::make('categories_count')
                            ->label('Categories')
                            ->state(fn (CategorySet $record): int => $record->categories()->count()),
                        TextEntry::make('books.name')
                            ->label('Used by')
                            ->badge()
                            ->url(fn (Book $relatedRecord): string => BookResource::getUrl('view', ['record' => $relatedRecord]))
                            ->placeholder('No Books yet'),
                    ]),
            ]);
    }
}
