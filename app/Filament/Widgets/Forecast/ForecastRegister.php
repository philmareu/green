<?php

namespace App\Filament\Widgets\Forecast;

use App\Enums\Frequency;
use App\Enums\RecurringKind;
use App\Filament\Actions\RecurringActions;
use App\Filament\Resources\Recurrings\RecurringResource;
use App\Filament\Widgets\Forecast\Concerns\InteractsWithForecast;
use App\Models\Recurring;
use App\Services\Recurring\ForecastEntry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\On;

/**
 * Every expected bill, income and flex budget in date order, with the running
 * balance after each.
 */
class ForecastRegister extends TableWidget
{
    use InteractsWithForecast;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    #[On(self::UPDATED_EVENT)]
    public function refreshForecast(): void
    {
        $this->cachedForecast = null;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $currency = fn (): string => $this->forecast()?->currency ?? 'USD';

        return $table
            ->heading('Register')
            ->description('Starts from today’s available balance, which already includes pending transactions.')
            ->records(fn (): array => collect($this->forecast()?->entries ?? [])
                ->mapWithKeys(fn (ForecastEntry $entry): array => [$entry->occurrenceId => [
                    'occurrence' => $entry->occurrenceId,
                    'recurring' => $entry->recurringId,
                    'date' => $entry->date->toDateString(),
                    'name' => $entry->name,
                    'note' => $entry->note ?? ($entry->isOneTime ? 'One time' : null),
                    'kind' => $entry->kind->value,
                    'amount' => $entry->amount,
                    'balance' => $entry->balance,
                    'one_time' => $entry->isOneTime,
                    'postponed' => $entry->isPostponed,
                ]])
                ->all())
            ->columns([
                TextColumn::make('date')
                    ->date('D, M j'),
                TextColumn::make('name')
                    ->description(fn (array $record): ?string => $record['note'])
                    ->url(fn (array $record): string => RecurringResource::getUrl('view', ['record' => $record['recurring']]))
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => RecurringKind::from($state)->getLabel())
                    ->color(fn (string $state): string => RecurringKind::from($state)->getColor()),
                TextColumn::make('amount')
                    ->money($currency)
                    ->color(fn (float $state): ?string => $state > 0 ? 'success' : null)
                    ->alignEnd(),
                TextColumn::make('balance')
                    ->money($currency)
                    ->color(fn (float $state): ?string => $state < 0 ? 'danger' : null)
                    ->weight('medium')
                    ->alignEnd(),
            ])
            ->headerActions([
                $this->addOneTimeAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    RecurringActions::link()
                        ->visible(fn (array $record): bool => $record['kind'] !== RecurringKind::Flex->value)
                        ->after(fn () => $this->dispatch(self::UPDATED_EVENT)),
                    RecurringActions::markPaid()
                        ->visible(fn (array $record): bool => $record['kind'] !== RecurringKind::Flex->value)
                        ->after(fn () => $this->dispatch(self::UPDATED_EVENT)),
                    RecurringActions::postpone()
                        ->visible(fn (array $record): bool => $record['kind'] !== RecurringKind::Flex->value)
                        ->after(fn () => $this->dispatch(self::UPDATED_EVENT)),
                    RecurringActions::restoreDueDate()
                        ->visible(fn (array $record): bool => $record['postponed'])
                        ->after(fn () => $this->dispatch(self::UPDATED_EVENT)),
                    RecurringActions::skip()
                        ->after(fn () => $this->dispatch(self::UPDATED_EVENT)),
                    Action::make('remove')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('This one-time item is deleted.')
                        ->visible(fn (array $record): bool => $record['one_time'])
                        ->action(fn (array $record) => RecurringActions::resolve($record)->recurring->delete())
                        ->after(fn () => $this->dispatch(self::UPDATED_EVENT)),
                ]),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nothing expected')
            ->emptyStateDescription('Add bills, flex spends and income under Recurring, or a one-time item here.');
    }

    /**
     * For the unexpected expense, or an invoice you expect to be paid.
     */
    protected function addOneTimeAction(): Action
    {
        return Action::make('addOneTime')
            ->label('Add one-time')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Add a one-time item')
            ->modalSubmitActionLabel('Add')
            ->disabled(fn (): bool => $this->forecastAccount() === null)
            ->schema([
                TextInput::make('name')
                    ->placeholder('e.g. Car repair')
                    ->required()
                    ->maxLength(255),
                ToggleButtons::make('direction')
                    ->options(['out' => 'Money out', 'in' => 'Money in'])
                    ->default('out')
                    ->inline()
                    ->required(),
                TextInput::make('amount')
                    ->numeric()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->required(),
                DatePicker::make('date')
                    ->default(today())
                    ->minDate(Recurring::EARLIEST_START)
                    ->required(),
                TextInput::make('payee')
                    ->label('Payee contains')
                    ->maxLength(255)
                    ->helperText('Optional. When a transaction with this in its description posts, Green links it and drops this from the forecast.'),
            ])
            ->action(function (array $data): void {
                $account = $this->forecastAccount();

                if ($account === null) {
                    return;
                }

                Recurring::query()->create([
                    'user_id' => auth()->id(),
                    'account_id' => $account->getKey(),
                    'kind' => $data['direction'] === 'in' ? RecurringKind::Income : RecurringKind::Bill,
                    'name' => $data['name'],
                    'amount' => $data['amount'],
                    'frequency' => Frequency::Once,
                    'starts_on' => $data['date'],
                    'payee' => filled($data['payee'] ?? null) ? $data['payee'] : null,
                    // An estimate: any amount from this payee counts.
                    'amount_tolerance' => null,
                ]);

                $this->dispatch(self::UPDATED_EVENT);
            });
    }
}
