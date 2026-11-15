<?php

namespace App\Filament\Actions;

use App\Enums\MatchSource;
use App\Enums\OccurrenceResolution;
use App\Models\Occurrence;
use App\Models\Transaction;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

/**
 * Actions on one due date, shared by a recurring item's page and the forecast
 * register. The register passes array rows, so each action looks the due date
 * up again, scoped to the signed-in user.
 */
class RecurringActions
{
    public static function link(): Action
    {
        return Action::make('link')
            ->label('Link transaction')
            ->icon(Heroicon::OutlinedLink)
            ->modalHeading('Link the transaction that paid this')
            ->modalSubmitActionLabel('Link')
            ->schema(fn (Occurrence|array $record): array => [
                Select::make('transaction_id')
                    ->label('Transaction')
                    ->options(static::resolve($record)->candidateTransactions()
                        ->mapWithKeys(fn (Transaction $transaction): array => [$transaction->getKey() => static::describe($transaction)])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Unlinked transactions on this account within 30 days of the due date.'),
            ])
            ->action(function (Occurrence|array $record, array $data): void {
                $occurrence = static::resolve($record);
                $transaction = $occurrence->candidateTransactions()->firstWhere('id', (int) $data['transaction_id']);

                if ($transaction !== null) {
                    $occurrence->link($transaction, MatchSource::Manual);
                }
            });
    }

    public static function unlink(): Action
    {
        return Action::make('unlink')
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Green won’t link this transaction to this item again.')
            ->action(fn (Occurrence|array $record) => static::resolve($record)->unlink());
    }

    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label('Mark paid')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->action(fn (Occurrence|array $record) => static::resolve($record)->update(['resolution' => OccurrenceResolution::Paid]));
    }

    public static function skip(): Action
    {
        return Action::make('skip')
            ->label('Skip this time')
            ->icon(Heroicon::OutlinedForward)
            ->color('gray')
            ->action(fn (Occurrence|array $record) => static::resolve($record)->update(['resolution' => OccurrenceResolution::Skipped]));
    }

    /**
     * Expect a bill or income later this time, without changing its schedule.
     */
    public static function postpone(): Action
    {
        return Action::make('postpone')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->modalHeading('Postpone this due date')
            ->modalDescription('The forecast and the overdue check use the new date. Later due dates stay on schedule.')
            ->modalSubmitActionLabel('Postpone')
            ->fillForm(fn (Occurrence|array $record): array => ['postponed_to' => static::resolve($record)->postponed_to])
            ->schema(fn (Occurrence|array $record): array => [
                DatePicker::make('postponed_to')
                    ->label('New date')
                    ->after(static::resolve($record)->due_on->toDateString())
                    ->validationMessages(['after' => 'Choose a date after the due date.'])
                    ->required(),
            ])
            ->action(fn (Occurrence|array $record, array $data) => static::resolve($record)->update(['postponed_to' => $data['postponed_to']]));
    }

    public static function restoreDueDate(): Action
    {
        return Action::make('restoreDueDate')
            ->label('Restore due date')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->action(fn (Occurrence|array $record) => static::resolve($record)->update(['postponed_to' => null]));
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->action(fn (Occurrence|array $record) => static::resolve($record)->update(['resolution' => null]));
    }

    /**
     * "Oct 14 · COMCAST CABLE 8823 · $91.20"
     */
    public static function describe(Transaction $transaction): string
    {
        return implode(' · ', [
            $transaction->display_date->format('M j'),
            $transaction->name,
            Number::currency(abs((float) $transaction->amount), $transaction->currency),
        ]);
    }

    /**
     * @param  Occurrence|array{occurrence: int}  $record
     */
    public static function resolve(Occurrence|array $record): Occurrence
    {
        if ($record instanceof Occurrence) {
            return $record;
        }

        return Occurrence::query()->ownedBy(auth()->user())->with('recurring')->findOrFail($record['occurrence']);
    }
}
