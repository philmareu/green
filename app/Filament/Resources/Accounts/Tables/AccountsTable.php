<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Actions\PlaidActions;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\Book;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AccountsTable
{
    public static function configure(Table $table, bool $withinBook = false): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['book', 'plaidItem']))
            ->columns(static::columns($withinBook))
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('book')
                    ->options(fn (): array => ['unassigned' => 'Unassigned']
                        + Book::query()->ownedBy(auth()->user())->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'unassigned' => $query->whereNull('book_id'),
                        default => $query->where('book_id', $data['value']),
                    })
                    ->visible(! $withinBook),
                TernaryFilter::make('is_hidden')
                    ->label('Hidden accounts')
                    ->placeholder('All accounts')
                    ->trueLabel('Hidden only')
                    ->falseLabel('Visible only')
                    ->default(false),
            ])
            ->recordActions([
                PlaidActions::reconnect()
                    ->iconButton()
                    ->tooltip('Reconnect bank'),
                ViewAction::make()
                    ->url(fn (Account $record): string => AccountResource::getUrl('view', ['record' => $record]))
                    ->iconButton()
                    ->tooltip('View'),
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Edit'),
                ActionGroup::make([
                    PlaidActions::sync(),
                    PlaidActions::refreshLiveBalance(),
                ]),
            ])
            ->emptyStateHeading('No accounts yet')
            ->emptyStateDescription('Open a Book and choose "Link bank account" to connect one through Plaid.');
    }

    /**
     * @return array<TextColumn>
     */
    public static function columns(bool $withinBook = false): array
    {
        return [
            TextColumn::make('name')
                ->formatStateUsing(fn (Account $record): string => $record->display_name)
                ->description(fn (Account $record): ?string => $record->plaidItem?->institution_name)
                ->searchable()
                ->sortable()
                ->weight('medium')
                ->wrap(),
            TextColumn::make('book.name')
                ->label('Book')
                ->badge()
                ->color(fn (Account $record) => $record->book?->kind?->getColor() ?? 'gray')
                ->placeholder('Unassigned')
                ->visible(! $withinBook),
            TextColumn::make('subtype')
                ->label('Type')
                ->formatStateUsing(fn (?string $state): string => Str::headline($state ?? ''))
                ->toggleable(),
            TextColumn::make('available_balance')
                ->label('Available')
                ->state(fn (Account $record): ?float => $record->spendableBalance())
                ->money(fn (Account $record): string => $record->currency)
                ->description(fn (Account $record): ?string => match (true) {
                    $record->isCredit() => 'credit remaining',
                    $record->available_balance === null => 'bank reports current only',
                    default => null,
                })
                ->weight('bold')
                ->alignEnd(),
            TextColumn::make('current_balance')
                ->label('Current')
                ->money(fn (Account $record): string => $record->currency)
                ->description(fn (Account $record): ?string => $record->isCredit() ? 'owed' : null)
                ->sortable()
                ->alignEnd(),
            TextColumn::make('plaidItem.status')
                ->label('Connection')
                ->badge()
                ->description(fn (Account $record): string => $record->plaidItem?->last_synced_at
                    ? 'Synced '.$record->plaidItem->last_synced_at->diffForHumans()
                    : 'Never synced')
                ->tooltip(fn (Account $record): ?string => $record->plaidItem?->error_message),
        ];
    }
}
