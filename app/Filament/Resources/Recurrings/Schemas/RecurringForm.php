<?php

namespace App\Filament\Resources\Recurrings\Schemas;

use App\Enums\Frequency;
use App\Enums\RecurringKind;
use App\Models\Account;
use App\Models\CategorySet;
use App\Models\Recurring;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RecurringForm
{
    public static function configure(Schema $schema, ?Account $account = null): Schema
    {
        return $schema->components(static::components($account));
    }

    /**
     * Plain fields with no record of their own, so the "Make recurring" action
     * on a transaction row can use them too. Given an account, as on an
     * account's page, the account picker is left out and that account is used.
     *
     * @return array<int, mixed>
     */
    public static function components(?Account $account = null): array
    {
        $isFlex = fn (Get $get): bool => $get->enum('kind', RecurringKind::class, isNullable: true) === RecurringKind::Flex;
        $accountId = fn (Get $get): mixed => $account?->getKey() ?? $get('account_id');

        return [
            ToggleButtons::make('kind')
                ->options(RecurringKind::class)
                ->default(RecurringKind::Bill)
                ->inline()
                ->required()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set) use ($isFlex): void {
                    if ($isFlex($get) && $get->enum('frequency', Frequency::class, isNullable: true) === Frequency::Once) {
                        $set('frequency', Frequency::Weekly->value);
                    }
                })
                ->columnSpanFull(),
            TextInput::make('name')
                ->placeholder('e.g. Comcast')
                ->required()
                ->maxLength(255),
            Select::make('account_id')
                ->label('Account')
                ->options(fn (): array => Account::query()
                    ->ownedBy(auth()->user())
                    ->visible()
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Account $account): array => [$account->getKey() => $account->display_name])
                    ->all())
                ->required()
                ->live()
                ->hidden($account !== null)
                ->helperText(fn (Get $get): string => $isFlex($get)
                    ? 'Use the account you pay with. Groceries bought on a card belong on the card; paying the card is a bill on checking.'
                    : 'The account it’s paid from or into.'),
            TextInput::make('amount')
                ->label(fn (Get $get): string => $isFlex($get) ? 'Budget per period' : 'Amount')
                ->numeric()
                ->prefix('$')
                ->minValue(0.01)
                ->required(),
            Select::make('frequency')
                ->options(fn (Get $get): array => collect(Frequency::cases())
                    ->reject(fn (Frequency $frequency): bool => $isFlex($get) && $frequency === Frequency::Once)
                    ->mapWithKeys(fn (Frequency $frequency): array => [$frequency->value => $frequency->getLabel()])
                    ->all())
                ->default(Frequency::Monthly)
                ->selectablePlaceholder(false)
                ->required(),
            DatePicker::make('starts_on')
                ->label(fn (Get $get): string => $isFlex($get) ? 'First period starts' : 'First due date')
                ->default(today())
                ->minDate(Recurring::EARLIEST_START)
                ->required(),
            DatePicker::make('ends_on')
                ->label('Ends on')
                ->afterOrEqual('starts_on')
                ->helperText('Optional.'),
            Toggle::make('autopay')
                ->label('Paid automatically')
                ->helperText('Off means you pay it yourself, and the lists flag it so it doesn’t slip.')
                ->visible(fn (Get $get): bool => $get->enum('kind', RecurringKind::class, isNullable: true) === RecurringKind::Bill)
                ->columnSpanFull(),
            Toggle::make('rolls_over')
                ->label('Roll over')
                ->helperText('What’s left of a period adds to the next period’s budget, and overspending takes from it.')
                ->visible($isFlex)
                ->columnSpanFull(),
            Section::make('Matching')
                ->description('How Green finds the transaction that paid it.')
                ->columns(2)
                ->columnSpanFull()
                ->hidden($isFlex)
                ->schema([
                    TextInput::make('payee')
                        ->label('Payee contains')
                        ->placeholder('e.g. comcast')
                        ->maxLength(255)
                        ->helperText('Green links a transaction only when its description contains this text, so a matching amount alone never counts. Leave out reference numbers that change. Empty means you link payments yourself.'),
                    TextInput::make('amount_tolerance')
                        ->label('Match amounts within')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->default(10)
                        ->helperText('Leave empty when the amount varies, like utilities or a card payment.'),
                ]),
            Select::make('category_id')
                ->label('Category')
                ->options(fn (Get $get): array => static::categorySet($accountId($get))?->categoryOptions() ?? [])
                ->searchable()
                ->required($isFlex)
                ->helperText(fn (Get $get): string => match (true) {
                    blank($accountId($get)) => 'Pick an account first.',
                    static::categorySet($accountId($get)) === null => 'This account’s Book has no category set. Pick one on the Book first.',
                    $isFlex($get) => 'Spending in this category and its subcategories counts against the budget. Categories come from the account’s Book.',
                    default => 'Optional. Linked payments get this category, unless you picked one for them yourself. Categories come from the account’s Book.',
                }),
        ];
    }

    /**
     * Form values for making a transaction recurring. The first due date is
     * moved back whole months, keeping the day, so earlier payments are found.
     *
     * @return array<string, mixed>
     */
    public static function fromTransaction(Transaction $transaction): array
    {
        $date = CarbonImmutable::parse($transaction->display_date)->startOfDay();
        $historyFrom = today()->subDays(Recurring::HISTORY_DAYS)->toImmutable();
        $startsOn = $date;

        for ($months = 1; ($earlier = $date->subMonthsNoOverflow($months))->gte($historyFrom); $months++) {
            if ($earlier->day === $date->day) {
                $startsOn = $earlier;
            }
        }

        $payee = $transaction->payee();

        return [
            'kind' => $transaction->isInflow() ? RecurringKind::Income->value : RecurringKind::Bill->value,
            'name' => $transaction->merchant_name ?: Str::title($payee),
            'account_id' => $transaction->account_id,
            'amount' => abs((float) $transaction->amount),
            'frequency' => Frequency::Monthly->value,
            'starts_on' => $startsOn->toDateString(),
            'payee' => $payee,
            'amount_tolerance' => 10,
            'category_id' => $transaction->category_id,
        ];
    }

    protected static function categorySet(mixed $accountId): ?CategorySet
    {
        if (blank($accountId)) {
            return null;
        }

        return Account::query()->ownedBy(auth()->user())->with('book.categorySet')->find($accountId)?->book?->categorySet;
    }
}
