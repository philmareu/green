<?php

namespace App\Filament\Resources\Recurrings\Tables;

use App\Enums\RecurringKind;
use App\Filament\Actions\RecurringActions;
use App\Filament\Resources\Recurrings\RecurringResource;
use App\Models\Account;
use App\Models\Recurring;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class RecurringsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account', 'category', 'occurrences.transaction']))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Recurring $record): string => $record->account?->display_name ?? 'Needs an account')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->badge(),
                TextColumn::make('amount')
                    ->money(fn (Recurring $record): string => $record->account?->currency ?? 'USD')
                    ->description(fn (Recurring $record): string => ($unit = $record->frequency->unit()) ? "per {$unit}" : 'one time')
                    ->sortable(),
                TextColumn::make('next')
                    ->label('Next')
                    ->state(fn (Recurring $record) => $record->nextOccurrence()?->due_on)
                    ->date('M j, Y')
                    ->description(fn (Recurring $record): ?string => $record->nextOccurrence()?->status()->getLabel())
                    ->color(fn (Recurring $record): ?string => $record->nextOccurrence()?->status()->getColor())
                    ->placeholder('Nothing due'),
                TextColumn::make('latest')
                    ->label('Latest')
                    ->state(fn (Recurring $record): ?string => static::latest($record))
                    ->placeholder('No payments yet')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->options(RecurringKind::class),
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->options(fn (): array => Account::query()
                        ->ownedBy(auth()->user())
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Account $account): array => [$account->getKey() => $account->display_name])
                        ->all()),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (Recurring $record): string => RecurringResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->emptyStateHeading('Nothing recurring yet')
            ->emptyStateDescription('Add bills, flex spends like groceries, and income, or use "Make recurring" on a transaction.');
    }

    /**
     * The last payment and how it compared, or what a flex spend has spent
     * this period.
     */
    protected static function latest(Recurring $record): ?string
    {
        $currency = $record->account?->currency ?? 'USD';

        if ($record->kind === RecurringKind::Flex) {
            $current = $record->nextOccurrence();

            return $current?->due_on->lte(today())
                ? Number::currency($current->spent(), $currency).' of '.Number::currency((float) $record->amount, $currency).' spent'
                : null;
        }

        $payment = $record->latestPayment();

        return $payment?->transaction ? RecurringActions::describe($payment->transaction) : null;
    }
}
