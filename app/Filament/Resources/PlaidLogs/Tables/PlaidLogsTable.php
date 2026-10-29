<?php

namespace App\Filament\Resources\PlaidLogs\Tables;

use App\Enums\PlaidLogKind;
use App\Enums\PlaidLogResult;
use App\Models\PlaidItem;
use App\Models\PlaidLog;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaidLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sinceTooltip()
                    ->sortable(),
                TextColumn::make('institution_name')
                    ->label('Connection')
                    ->placeholder('Unknown bank')
                    ->description(fn (PlaidLog $record): ?string => $record->plaid_item_id === null ? 'Not connected' : null)
                    ->weight('medium'),
                TextColumn::make('kind')
                    ->label('Activity')
                    ->description(fn (PlaidLog $record): ?string => $record->triggered_by?->getLabel()),
                TextColumn::make('result')
                    ->badge()
                    ->description(fn (PlaidLog $record): ?string => collect([$record->error_code, $record->error_message])->filter()->implode(': ') ?: null)
                    ->wrap(),
                TextColumn::make('changes')
                    ->state(fn (PlaidLog $record): ?string => $record->added === null
                        ? null
                        : "{$record->added} added, {$record->modified} updated, {$record->removed} removed"),
                TextColumn::make('pages')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): ?string => match (true) {
                        $state === null => null,
                        $state < 1000 => "{$state} ms",
                        default => number_format($state / 1000, 1).' s',
                    })
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('requests')
                    ->label('Plaid requests')
                    ->state(fn (PlaidLog $record): array => collect($record->requests ?? [])
                        ->map(fn (array $request): string => collect([
                            $request['endpoint'],
                            $request['request_id'],
                            $request['error_code'],
                        ])->filter()->implode(' · '))
                        ->all())
                    ->listWithLineBreaks()
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::ExtraSmall)
                    ->toggleable(),
            ])
            ->defaultSort(fn (Builder $query) => $query
                ->orderByDesc($query->qualifyColumn('started_at'))
                ->orderByDesc($query->qualifyColumn('id')))
            ->filters([
                SelectFilter::make('plaid_item_id')
                    ->label('Connection')
                    ->options(fn (): array => PlaidItem::query()->ownedBy(auth()->user())->orderBy('institution_name')->get()
                        ->mapWithKeys(fn (PlaidItem $item): array => [$item->getKey() => $item->institution_name ?? 'Unknown bank'])
                        ->all()),
                SelectFilter::make('kind')
                    ->label('Activity')
                    ->options(PlaidLogKind::class),
                SelectFilter::make('result')
                    ->options(PlaidLogResult::class),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No Plaid activity yet')
            ->emptyStateDescription('Syncs, Live balance refreshes, bank links and removals show here.');
    }
}
