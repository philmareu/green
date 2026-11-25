<?php

use App\Enums\CategorySource;
use App\Enums\MatchSource;
use App\Enums\OccurrenceStatus;
use App\Enums\PlaidItemStatus;
use App\Enums\PlaidLogKind;
use App\Enums\PlaidLogResult;
use App\Filament\Pages\Forecast;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Books\BookResource;
use App\Filament\Resources\CategorySets\CategorySetResource;
use App\Filament\Resources\PlaidItems\PlaidItemResource;
use App\Filament\Resources\PlaidLogs\PlaidLogResource;
use App\Filament\Resources\Recurrings\RecurringResource;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Account;
use App\Models\Book;
use App\Models\Occurrence;
use App\Models\PlaidItem;
use App\Models\PlaidLog;
use App\Models\Recurring;
use App\Models\Reminder;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AccountWentNegative;
use App\Notifications\BudgetAlert;
use App\Notifications\RecurringReminder;
use App\Services\Recurring\ForecastBalance;
use App\Services\Recurring\ForecastEntry;
use Database\Seeders\DemoSeeder;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-25 10:30');
});

function demoUser(): User
{
    return User::query()->where('email', DemoSeeder::EMAIL)->sole();
}

/**
 * @return array<string, int> rows per table the demo writes to
 */
function demoRowCounts(): array
{
    return collect(['users', 'books', 'category_sets', 'categories', 'plaid_items', 'accounts', 'transactions', 'recurrings', 'occurrences', 'reminders', 'notifications', 'plaid_logs'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

it('loads the whole demo without calling Plaid or sending mail, whatever the date', function (string $today) {
    $this->travelTo($today);
    Http::fake();
    Mail::fake();

    $this->seed();

    Http::assertNothingSent();
    Mail::assertNothingOutgoing();

    $user = demoUser();
    $books = $user->books()->with('accounts')->get()->keyBy('name');
    $accounts = Account::query()->ownedBy($user)->get()->keyBy('name');
    $transactions = Transaction::query()->ownedBy($user)->with('account')->get();
    $recurrings = Recurring::query()->ownedBy($user)->with('occurrences.transaction')->get()->keyBy('name');
    $period = fn (string $name): Occurrence => $recurrings[$name]->nextOccurrence();
    $values = fn (iterable $enums): array => collect($enums)->map->value->unique()->sort()->values()->all();

    // Books, banks and accounts.
    expect($books['Acme Consulting LLC'])->cashAvailable()->toBe(14286.40)->creditOwed()->toBe(2318.40)
        ->and($books['Personal'])->cashAvailable()->toBe(2365.87)->creditOwed()->toBe(1243.87)
        ->and($accounts['Everyday Checking']->plaidItem->accounts->pluck('book_id')->unique())->toHaveCount(2)
        ->and($accounts['Joint Checking']->spendableBalance())->toBe(-46.80)
        ->and($accounts['Share Savings']->book_id)->toBeNull()
        ->and($accounts['Share Savings']->plaidItem->status)->toBe(PlaidItemStatus::LoginRequired);

    // About 90 days of transactions, some pending in each Book, categorized every way.
    expect($transactions->min('date'))->toBeBetween(today()->subDays(93), today()->subDays(80))
        ->and($transactions->max('date')->toDateString())->toBe(today()->toDateString())
        ->and($books->every(fn (Book $book): bool => $book->transactions()->where('pending', true)->exists()))->toBeTrue()
        ->and($values($transactions->pluck('category_source')->filter()))->toBe($values(CategorySource::cases()))
        ->and($transactions->whereNull('category_id')->whereNotNull('account.book_id'))->not->toBeEmpty();

    // Recurring: one overdue bill, every status a due date can have, payments linked both ways.
    expect(Recurring::query()->ownedBy($user)->overdue()->pluck('name')->all())->toBe(['Water and sewer'])
        ->and($values($recurrings->flatMap->occurrences->map->status()))->toBe($values(OccurrenceStatus::cases()))
        ->and($recurrings['Rent']->occurrences->where('matched_by', MatchSource::Manual)->sole()->transaction->name)->toBe('CHECK 1042')
        ->and($recurrings['Rent']->occurrences->where('matched_by', MatchSource::Auto))->not->toBeEmpty()
        ->and($recurrings->filter->needsSetAside()->sum->monthlySetAside())->toBe(968.00);

    // Flex spends: one under budget, one over after overspending rolled in, one at the warning level.
    expect($period('Groceries'))->budget()->toBe(188.32)->spent()->toBe(84.62)->budget_alert->toBeNull()
        ->and($period('Eating out'))->budget()->toBe(104.50)->spent()->toBe(118.30)->budget_alert->toBe(100)
        ->and($period('Fuel'))->budget()->toBe(145.00)->spent()->toBe(124.85)->budget_alert->toBe(80);

    // The forecast dips when rent is paid, three days before the paycheck.
    $forecast = app(ForecastBalance::class)->handle($accounts['Everyday Checking'], today()->addWeeks(6));

    expect($forecast->starting)->toBe(2412.67)
        ->and($forecast->lowest()['balance'])->toBe(212.62)
        ->and($forecast->lowest()['date']->toDateString())->toBe(today()->addDays(6)->toDateString())
        ->and(collect($forecast->entries)->first(fn (ForecastEntry $entry): bool => $entry->name === 'Paycheck'))
        ->date->toDateString()->toBe(today()->addDays(9)->toDateString())
        ->balance->toBe(2462.62);

    // Alerts of each type, reminders still to come, and Plaid activity that went well and badly.
    expect($user->notifications()->pluck('type')->countBy()->all())->toEqual([
        AccountWentNegative::class => 1,
        BudgetAlert::class => 2,
        RecurringReminder::class => 2,
    ])
        ->and(Reminder::query()->ownedBy($user)->count())->toBe(3)
        ->and(PlaidLog::query()->ownedBy($user)->where('result', PlaidLogResult::Succeeded)->exists())->toBeTrue()
        ->and(PlaidLog::query()->ownedBy($user)->where('result', PlaidLogResult::Failed)->exists())->toBeTrue()
        ->and(PlaidLog::query()->ownedBy($user)->where('kind', PlaidLogKind::LiveBalance)->exists())->toBeTrue();
})->with([
    'an ordinary day' => '2026-11-25 10:30',
    'a Monday, the 1st, after a short February' => '2027-03-01 08:00',
    'with bills due on the 29th, 30th and 31st, before February' => '2027-01-28 23:30',
    'late December, with due dates either side of New Year' => '2026-12-29 12:00',
]);

it('lets the demo login sign in and renders each main page', function () {
    $this->seed();
    $user = demoUser();

    Livewire::test(Login::class)
        ->fillForm(['email' => 'test@example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);

    // Widgets and relation managers load lazily. Render them with the page, so they meet the data too.
    Livewire::withoutLazyLoading();
    $personal = $user->books()->where('name', 'Personal')->sole();
    $everyday = Account::query()->ownedBy($user)->where('name', 'Everyday Checking')->sole();
    $recurring = fn (string $name): Recurring => $user->recurrings()->where('name', $name)->sole();

    $pages = [
        '/' => ['$14,286.40', 'Little Sprouts Daycare'],
        BookResource::getUrl() => ['Acme Consulting LLC'],
        BookResource::getUrl('view', ['record' => $personal]) => ['$2,365.87', 'Rewards Visa'],
        AccountResource::getUrl() => ['Share Savings', 'Unassigned'],
        AccountResource::getUrl('view', ['record' => $everyday]) => ['$2,412.67', 'View forecast'],
        TransactionResource::getUrl() => ['Comcast', 'Pending'],
        CategorySetResource::getUrl() => ['Business', 'Personal'],
        CategorySetResource::getUrl('view', ['record' => $personal->categorySet]) => ['Groceries', 'costco'],
        RecurringResource::getUrl() => ['Water and sewer', 'Overdue', '$968.00'],
        RecurringResource::getUrl('view', ['record' => $recurring('Rent')]) => ['Linked by you', 'Move the rent over from savings'],
        RecurringResource::getUrl('view', ['record' => $recurring('Eating out')]) => ['$104.50', 'rolled over'],
        Forecast::getUrl() => ['Everyday Checking ••4242', '$212.62', 'Overdue since'],
        PlaidItemResource::getUrl() => ['Tattersall Federal Credit Union', 'Reconnect required'],
        PlaidLogResource::getUrl() => ['Live balance', 'ITEM_LOGIN_REQUIRED'],
        NotificationPreferences::getUrl() => ['Budget alerts'],
    ];

    foreach ($pages as $url => $text) {
        $this->get($url)->assertOk()->assertSee($text);
    }
});

it('rebuilds the demo on a second run, without duplicating it or changing the login', function () {
    $this->seed(DemoSeeder::class);
    $firstRun = demoRowCounts();
    $user = demoUser();
    $user->forceFill(['password' => 'changed-by-hand', 'notification_preferences' => ['negative_balance' => ['mail']]])->save();
    $extraBook = Book::factory()->for($user)->create();
    Mail::fake();

    $this->seed(DemoSeeder::class);

    expect(demoRowCounts())->toBe($firstRun)
        ->and(Hash::check('changed-by-hand', $user->fresh()->password))->toBeTrue();
    $this->assertModelMissing($extraBook);
    Mail::assertNothingOutgoing();
});

it('refuses to load when the database has another login, and leaves everything as it was', function () {
    $account = Account::factory()->inBook(Book::factory()->create())->create();
    $transaction = Transaction::factory()->for($account)->create();
    $recurring = Recurring::factory()->for($account)->create();
    $before = demoRowCounts();

    expect(fn () => $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->run())
        ->toThrow(RuntimeException::class, 'already has a real login');

    expect(demoRowCounts())->toBe($before);
    $this->assertModelExists($account->book);
    $this->assertModelExists($transaction);
    $this->assertModelExists($recurring);
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
});

it('refuses to rebuild the demo once another login has been added', function () {
    $demoBook = Book::factory()->for(User::factory()->create(['email' => 'test@example.com']))->create();
    User::factory()->create();
    $before = demoRowCounts();

    expect(fn () => $this->seed())->toThrow(RuntimeException::class, 'already has a real login');

    expect(demoRowCounts())->toBe($before);
    $this->assertModelExists($demoBook);
});

it('refuses to rebuild once a real bank is linked to the demo login', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);
    $linked = PlaidItem::factory()->for($user)->create();
    $before = demoRowCounts();

    expect(fn () => $this->seed())->toThrow(RuntimeException::class, 'did not come from the demo');

    expect(demoRowCounts())->toBe($before);
    $this->assertModelExists($linked);
});

it('refuses to load in production, even when forced', function () {
    $this->app['env'] = 'production';

    expect(fn () => $this->artisan('db:seed', ['--force' => true])->run())
        ->toThrow(RuntimeException::class, 'does not run in production');

    $this->assertDatabaseEmpty('users');
});
