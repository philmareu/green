<?php

namespace App\Filament\Resources\Transactions\Tables;

use App\Enums\CategorySource;
use App\Enums\MatchSource;
use App\Enums\RecurringKind;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Books\BookResource;
use App\Filament\Resources\Recurrings\RecurringResource;
use App\Filament\Resources\Recurrings\Schemas\RecurringForm;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\Occurrence;
use App\Models\Recurring;
use App\Models\Transaction;
use App\Services\Categories\CategorizeTransactions;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

use function Filament\Support\generate_href_html;

class TransactionsTable
{
    public static function configure(Table $table, bool $withinBook = false, bool $withinAccount = false): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account.book.categorySet', 'account.plaidItem', 'occurrence.recurring']))
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
                SelectFilter::make('category')
                    ->options(fn (): array => ['uncategorized' => 'Uncategorized']
                        + CategorySet::query()->ownedBy(auth()->user())->orderBy('name')->get()
                            ->mapWithKeys(fn (CategorySet $set): array => [$set->name => collect($set->categoryOptions())->collapseWithKeys()->all()])
                            ->all())
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'uncategorized' => $query->whereNull($query->qualifyColumn('category_id')),
                        // A parent category also matches its subcategories.
                        default => $query->whereIn($query->qualifyColumn('category_id'), Category::query()
                            ->ownedBy(auth()->user())
                            ->where(fn (Builder $category) => $category->whereKey($data['value'])->orWhere('parent_id', $data['value']))
                            ->select('id')),
                    })
                    ->searchable(),
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
            ->recordActions([
                static::categorizePayeeAction(),
                static::makeRecurringAction(),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No transactions yet')
            ->emptyStateDescription('Transactions appear here after a bank is linked and synced.');
    }

    /**
     * @return array<Column>
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
                ->description(fn (Transaction $record): string|Htmlable|null => match (true) {
                    $record->occurrence?->recurring !== null => static::recurringDescription($record->occurrence),
                    filled($record->category_primary) => Str::headline(Str::lower($record->category_primary)),
                    default => null,
                })
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                    ->where($query->qualifyColumn('name'), 'like', "%{$search}%")
                    ->orWhere($query->qualifyColumn('merchant_name'), 'like', "%{$search}%")))
                ->wrap(),
            static::categoryColumn(),
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
                ->url(fn (Transaction $record): ?string => $record->account?->book
                    ? BookResource::getUrl('view', ['record' => $record->account->book])
                    : null)
                ->visible(! ($withinBook || $withinAccount)),
            TextColumn::make('account.name')
                ->label('Account')
                ->formatStateUsing(fn (Transaction $record): string => $record->account->display_name)
                ->url(fn (Transaction $record): string => AccountResource::getUrl('view', ['record' => $record->account]))
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

    /**
     * "↻ Comcast · due Oct 15", with the name linking to the recurring item
     * the transaction pays.
     */
    protected static function recurringDescription(Occurrence $occurrence): Htmlable
    {
        return new HtmlString(sprintf(
            '↻ <a %s style="text-decoration: underline; text-underline-offset: 2px;">%s</a> · due %s',
            generate_href_html(RecurringResource::getUrl('view', ['record' => $occurrence->recurring]))->toHtml(),
            e($occurrence->recurring->name),
            e($occurrence->due_on->format('M j')),
        ));
    }

    /**
     * Give a category to every transaction from this one's payee, now and
     * after each sync, by adding the payee to the category.
     */
    public static function categorizePayeeAction(): Action
    {
        return Action::make('categorizePayee')
            ->label('Categorize payee')
            ->icon(Heroicon::OutlinedTag)
            ->iconButton()
            ->tooltip('Categorize everything from this payee')
            ->color('gray')
            ->modalHeading('Categorize everything from this payee')
            ->modalDescription('Green adds the payee to the category, so its transactions get it now and after each sync. Ones you categorized yourself keep their category.')
            ->modalSubmitActionLabel('Categorize')
            ->visible(fn (Transaction $record): bool => $record->account?->book?->categorySet !== null)
            ->schema(fn (Transaction $record): array => [
                TextInput::make('payee')
                    ->label('Payee contains')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Transactions whose description contains this get the category. Leave out reference numbers that change.'),
                Select::make('category_id')
                    ->label('Category')
                    ->options($record->account?->book?->categorySet?->categoryOptions() ?? [])
                    ->searchable()
                    ->required(),
            ])
            ->fillForm(fn (Transaction $record): array => ['payee' => $record->payee(), 'category_id' => $record->category_id])
            ->action(function (Transaction $record, array $data, CategorizeTransactions $categorize): void {
                $set = $record->account->book->categorySet;
                $category = $set->categories()->findOrFail($data['category_id']);

                $category->update(['payees' => [...($category->payees ?? []), $data['payee']]]);
                $changed = $categorize->forCategorySet($set);

                Notification::make()
                    ->title("“{$data['payee']}” now goes in {$category->full_name}")
                    ->body(Str::plural('transaction', $changed, prependCount: true).' changed. New ones get it after each sync.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Turn a transaction into a recurring item, filled in from it. Saving links
     * this transaction and earlier ones from the same payee.
     */
    public static function makeRecurringAction(): Action
    {
        return Action::make('makeRecurring')
            ->label('Make recurring')
            ->icon(Heroicon::OutlinedArrowPath)
            ->iconButton()
            ->tooltip('Make recurring')
            ->color('gray')
            ->modalHeading('Make recurring')
            ->modalDescription('Green links this transaction, and earlier ones from the same payee, to the new item.')
            ->modalSubmitActionLabel('Add')
            ->visible(fn (Transaction $record): bool => $record->occurrence === null)
            ->schema(RecurringForm::components())
            ->fillForm(fn (Transaction $record): array => RecurringForm::fromTransaction($record))
            ->action(function (Transaction $record, array $data): void {
                $recurring = Recurring::query()->create([...$data, 'user_id' => auth()->id()]);

                static::linkSource($recurring, $record);
            })
            ->successNotificationTitle('Added to Recurring');
    }

    /**
     * Link the transaction an item was made from, if matching didn't already.
     */
    protected static function linkSource(Recurring $recurring, Transaction $transaction): void
    {
        if ($recurring->kind === RecurringKind::Flex
            || $recurring->account_id !== $transaction->account_id
            || $transaction->occurrence()->exists()) {
            return;
        }

        $date = CarbonImmutable::parse($transaction->display_date)->startOfDay();
        $occurrence = $recurring->occurrences()->whereDate('due_on', $date->toDateString())->first()
            ?? $recurring->occurrences()->create(['due_on' => $date]);

        if ($occurrence->transaction_id === null) {
            $occurrence->link($transaction, MatchSource::Manual);
        }
    }

    /**
     * Pick a category from the transaction's Book's set. Suggested categories
     * are shown in italics until you pick one yourself.
     */
    public static function categoryColumn(): SelectColumn
    {
        /** @var array<int, array<int, string>> $optionsBySet */
        $optionsBySet = [];

        return SelectColumn::make('category_id')
            ->label('Category')
            ->options(function (Transaction $record) use (&$optionsBySet): array {
                $set = $record->account?->book?->categorySet;

                // Native selects can't show option groups, so list them in order instead.
                return $set === null ? [] : ($optionsBySet[$set->getKey()] ??= collect($set->categoryOptions())->collapseWithKeys()->all());
            })
            ->placeholder(fn (Transaction $record): string => $record->account?->book?->categorySet ? 'Uncategorized' : 'No category set')
            ->disabled(fn (Transaction $record): bool => $record->account?->book?->categorySet === null)
            ->extraInputAttributes(fn (Transaction $record): array => [
                'title' => $record->category_source?->getLabel(),
                'style' => 'min-width: 12rem;'.($record->category_source === CategorySource::Suggested ? ' font-style: italic;' : ''),
            ])
            ->updateStateUsing(function (Transaction $record, ?string $state): ?string {
                $record->update([
                    'category_id' => filled($state) ? (int) $state : null,
                    'category_source' => filled($state) ? CategorySource::Manual : null,
                ]);

                return $state;
            });
    }
}
