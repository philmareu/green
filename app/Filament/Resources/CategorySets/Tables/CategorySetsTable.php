<?php

namespace App\Filament\Resources\CategorySets\Tables;

use App\Services\Categories\StarterCategories;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategorySetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('books')->withCount('categories'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('kind')
                    ->badge(),
                TextColumn::make('categories_count')
                    ->label('Categories')
                    ->numeric(),
                TextColumn::make('books.name')
                    ->label('Used by')
                    ->badge()
                    ->placeholder('No Books'),
            ])
            ->defaultSort('name')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No category sets yet')
            ->emptyStateDescription('Start with suggested Business and Personal sets, or create your own.')
            ->emptyStateActions([
                Action::make('addStarterSets')
                    ->label('Add starter sets')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->action(function (StarterCategories $starters): void {
                        $starters->createSetsFor(auth()->user());

                        Notification::make()
                            ->title('Added Business and Personal category sets')
                            ->body('Books without categories now use the set that matches their kind.')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
