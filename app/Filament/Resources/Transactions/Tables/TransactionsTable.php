<?php

namespace App\Filament\Resources\Transactions\Tables;

use App\Models\Account;
use App\Models\Book;
use App\Models\Transaction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TransactionsTable
{
    public static function configure(Table $table, bool $withinBook = false, bool $withinAccount = false): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account.book', 'account.plaidItem']))
            ->columns(static::columns($withinBook, $withinAccount))
            ->defaultSort(fn (Builder $query) => $query
                ->orderByDesc($query->qualifyColumn('pending'))
                ->orderByDesc($query->qualifyColumn('date'))
                ->orderByDesc($query->qualifyColumn('id')))
            ->filters([
                SelectFilter::make('book')
                    ->options(fn (): array => Book::query()->ownedBy(auth()->user())->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('account', fn (Builder $account) => $account->where('book_id', $data['value']))
                        : $query)
                    ->visible(! ($withinBook || $withinAccount)),
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->options(fn (): array => Account::query()->ownedBy(auth()->user())->orderBy('name')->get()
                        ->mapWithKeys(fn (Account $account) => [$account->getKey() => $account->display_name])
                        ->all())
                    ->searchable()
                    ->visible(! $withinAccount),
                TernaryFilter::make('pending')
                    ->placeholder('Pending and posted')
                    ->trueLabel('Pending only')
                    ->falseLabel('Posted only'),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate($query->qualifyColumn('date'), '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate($query->qualifyColumn('date'), '<=', $date)))
                    ->indicateUsing(fn (array $data): ?string => match (true) {
                        filled($data['from'] ?? null) && filled($data['until'] ?? null) => "{$data['from']} to {$data['until']}",
                        filled($data['from'] ?? null) => "From {$data['from']}",
                        filled($data['until'] ?? null) => "Until {$data['until']}",
                        default => null,
                    }),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No transactions yet')
            ->emptyStateDescription('Transactions appear here after a bank is linked and synced.');
    }

    /**
     * @return array<TextColumn>
     */
    public static function columns(bool $withinBook = false, bool $withinAccount = false): array
    {
        return [
            TextColumn::make('date')
                ->state(fn (Transaction $record) => $record->display_date)
                ->date()
                ->sortable(),
            TextColumn::make('name')
                ->label('Description')
                ->state(fn (Transaction $record): string => $record->description)
                ->description(fn (Transaction $record): ?string => $record->category_primary
                    ? Str::headline(Str::lower($record->category_primary))
                    : null)
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                    ->where($query->qualifyColumn('name'), 'like', "%{$search}%")
                    ->orWhere($query->qualifyColumn('merchant_name'), 'like', "%{$search}%")))
                ->wrap(),
            TextColumn::make('pending')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Pending' : 'Posted')
                ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
            TextColumn::make('account.book.name')
                ->label('Book')
                ->badge()
                ->color(fn (Transaction $record) => $record->account?->book?->kind?->getColor() ?? 'gray')
                ->placeholder('Unassigned')
                ->visible(! ($withinBook || $withinAccount)),
            TextColumn::make('account.name')
                ->label('Account')
                ->formatStateUsing(fn (Transaction $record): string => $record->account->display_name)
                ->toggleable()
                ->visible(! $withinAccount),
            TextColumn::make('amount')
                ->state(fn (Transaction $record): float => $record->signed_amount)
                ->money(fn (Transaction $record): string => $record->currency)
                ->color(fn (float $state): ?string => $state > 0 ? 'success' : null)
                ->weight('medium')
                ->alignEnd(),
        ];
    }
}
