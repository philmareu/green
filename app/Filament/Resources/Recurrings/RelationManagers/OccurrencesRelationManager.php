<?php

namespace App\Filament\Resources\Recurrings\RelationManagers;

use App\Enums\RecurringKind;
use App\Filament\Actions\RecurringActions;
use App\Models\Occurrence;
use App\Models\Recurring;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Due dates, newest first, with the transaction that paid each one and how it
 * was linked.
 */
class OccurrencesRelationManager extends RelationManager
{
    protected static string $relationship = 'occurrences';

    protected static ?string $title = 'Due dates';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Recurring $recurring */
        $recurring = $this->getOwnerRecord();
        $isFlex = $recurring->kind === RecurringKind::Flex;
        $currency = $recurring->account?->currency ?? 'USD';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('transaction')->reorder())
            ->defaultSort('due_on', 'desc')
            ->columns([
                TextColumn::make('due_on')
                    ->label($isFlex ? 'Period' : 'Due')
                    ->date('D, M j, Y')
                    ->description(fn (Occurrence $record): ?string => match (true) {
                        $isFlex => ($end = $record->periodEnd()) ? 'until '.$end->subDay()->format('M j') : null,
                        $record->postponed_to !== null => 'postponed to '.$record->postponed_to->format('M j'),
                        default => null,
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (Occurrence $record) => $record->status())
                    ->badge(),
                TextColumn::make('transaction_id')
                    ->label('Paid by')
                    ->state(fn (Occurrence $record): ?string => $record->transaction ? RecurringActions::describe($record->transaction) : null)
                    ->description(fn (Occurrence $record): ?string => $record->transaction
                        ? trim($record->matched_by?->getLabel().' '.static::difference($record, $currency))
                        : null)
                    ->placeholder('—')
                    ->wrap()
                    ->visible(! $isFlex),
                TextColumn::make('budget')
                    ->state(fn (Occurrence $record): ?float => $record->due_on->lte(today()) ? $record->budget() : null)
                    ->money($currency)
                    ->description(fn (Occurrence $record): ?string => ($rolledOver = $record->due_on->lte(today()) ? $record->rolledOver() : 0.0) != 0
                        ? ($rolledOver > 0 ? '+' : '−').Number::currency(abs($rolledOver), $currency).' rolled over'
                        : null)
                    ->placeholder('—')
                    ->visible($isFlex && $recurring->rolls_over),
                TextColumn::make('spent')
                    ->state(fn (Occurrence $record): ?float => $record->due_on->lte(today()) ? $record->spent() : null)
                    ->money($currency)
                    ->placeholder('—')
                    ->visible($isFlex),
                TextColumn::make('left')
                    ->state(fn (Occurrence $record): ?float => $record->due_on->lte(today()) ? $record->left() : null)
                    ->money($currency)
                    ->placeholder('—')
                    ->visible($isFlex),
            ])
            ->recordActions([
                RecurringActions::link()
                    ->link()
                    ->visible(fn (Occurrence $record): bool => ! $isFlex && $record->isOpen()),
                RecurringActions::unlink()
                    ->link()
                    ->visible(fn (Occurrence $record): bool => $record->transaction_id !== null),
                ActionGroup::make([
                    RecurringActions::markPaid()
                        ->visible(fn (Occurrence $record): bool => ! $isFlex && $record->isOpen()),
                    RecurringActions::postpone()
                        ->visible(fn (Occurrence $record): bool => ! $isFlex && $record->isOpen()),
                    RecurringActions::restoreDueDate()
                        ->visible(fn (Occurrence $record): bool => $record->postponed_to !== null && $record->isOpen()),
                    RecurringActions::skip()
                        ->visible(fn (Occurrence $record): bool => $record->isOpen()),
                    RecurringActions::reopen()
                        ->visible(fn (Occurrence $record): bool => $record->resolution !== null),
                ]),
            ])
            ->emptyStateHeading('No due dates yet')
            ->emptyStateDescription('Due dates appear from the first due date on.');
    }

    /**
     * "(+$2.20)" when the payment differs from the expected amount.
     */
    protected static function difference(Occurrence $record, string $currency): string
    {
        $difference = round(abs((float) $record->transaction->amount) - (float) $record->recurring->amount, 2);

        if ($difference == 0) {
            return '';
        }

        return '('.($difference > 0 ? '+' : '−').Number::currency(abs($difference), $currency).')';
    }
}
