<?php

namespace App\Filament\Resources\Books\Tables;

use App\Models\Book;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BooksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('accounts')->withCount('accounts'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('kind')
                    ->badge(),
                TextColumn::make('accounts_count')
                    ->label('Accounts')
                    ->numeric(),
                TextColumn::make('cash_available')
                    ->label('Cash available')
                    ->state(fn (Book $record): float => $record->cashAvailable())
                    ->money(fn (Book $record): string => $record->currency())
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('credit_owed')
                    ->label('Credit owed')
                    ->state(fn (Book $record): float => $record->creditOwed())
                    ->money(fn (Book $record): string => $record->currency())
                    ->alignEnd(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No Books yet')
            ->emptyStateDescription('Create a Book for each business or household you track, then link its bank accounts.');
    }
}
