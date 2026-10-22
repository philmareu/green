<?php

namespace App\Filament\Resources\PlaidItems\Tables;

use App\Filament\Actions\PlaidActions;
use App\Models\PlaidItem;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaidItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('accounts'))
            ->columns([
                TextColumn::make('institution_name')
                    ->label('Bank')
                    ->placeholder('Unknown institution')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (PlaidItem $record): ?string => $record->error_message),
                TextColumn::make('accounts_count')
                    ->label('Accounts')
                    ->numeric(),
                TextColumn::make('transactions_update_status')
                    ->label('History')
                    ->badge()
                    ->formatStateUsing(fn (PlaidItem $record): string => $record->isHistoryLoading() ? 'Loading' : 'Complete')
                    ->color(fn (PlaidItem $record): string => $record->isHistoryLoading() ? 'info' : 'gray')
                    ->placeholder('Not synced'),
                TextColumn::make('last_synced_at')
                    ->label('Last synced')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->recordActions([
                PlaidActions::reconnect(),
                ActionGroup::make([
                    PlaidActions::sync(),
                    PlaidActions::remove(),
                ]),
            ])
            ->emptyStateHeading('No banks connected')
            ->emptyStateDescription('Link a bank from a Book to start syncing balances and transactions.');
    }
}
