<?php

namespace Database\Seeders;

use App\Enums\CategorySource;
use App\Enums\Frequency;
use App\Enums\MatchSource;
use App\Enums\OccurrenceResolution;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\Occurrence;
use App\Models\PlaidItem;
use App\Models\PlaidLog;
use App\Models\Recurring;
use App\Models\Reminder;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RecurringReminder;
use App\Services\Categories\CategorizeTransactions;
use App\Services\Categories\StarterCategories;
use App\Services\Notifications\NotifyBudgetAlerts;
use App\Services\Notifications\NotifyNegativeBalances;
use App\Services\Recurring\MatchRecurringTransactions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RecurringFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * The demo: one login with two Books, three bank connections and three months
 * of history, so every screen can be tried without Plaid keys.
 *
 *   php artisan migrate --seed    a new database
 *   php artisan db:seed           a database that's already migrated
 *
 * Then sign in as test@example.com / password. That password is public, so the
 * demo only loads where nobody's real data lives: it refuses to run in
 * production, and in a database that has any other login. A real install
 * starts with `php artisan green:install` instead.
 *
 * Running it again rebuilds what the demo login owns from scratch and keeps
 * the login itself, so a changed password stays changed. It refuses once a
 * real bank has been linked to the demo login, which a rebuild would delete.
 *
 * Every date counts from today, so the demo looks the same whenever it's
 * loaded: rent is always due in six days and payday in nine. The amounts
 * behind what a screen says (balances, bills, budgets) are fixed; only the
 * everyday spending in `DemoStatements::SPENDING` is drawn at random, from a
 * fixed seed.
 *
 * Rows are written the way the app writes them: the bank's side with
 * factories, then Green's own services and model events categorize, link
 * payments and raise alerts. Nothing here talks to Plaid, so "Sync now" and
 * "Live balance" fail on these accounts.
 */
class DemoSeeder extends Seeder
{
    public const string EMAIL = 'test@example.com';

    public const string PASSWORD = 'password';

    /**
     * The demo's bank connections have Plaid ids that start with this. Plaid's
     * own never do, which is how a re-run tells them apart.
     */
    public const string ITEM_PREFIX = 'demo-';

    /** How far back the transactions go: the default of PLAID_DAYS_REQUESTED. */
    private const int DAYS = 90;

    /** What Plaid answers once a bank wants its customer to sign in again. */
    private const string LOGIN_REQUIRED = 'the login details of this item have changed (credentials, MFA, or required user action) and a user login is required to update this information. use Link\'s update mode to restore the item to a good state';

    private User $user;

    private Book $business;

    private Book $personal;

    /** @var array<string, Account> */
    private array $accounts = [];

    /** Seeded, so the everyday spending is the same on every run. */
    private Randomizer $random;

    public function run(): void
    {
        $user = User::query()->firstWhere('email', self::EMAIL);

        $this->refuseWhereTheDemoDoesNotBelong($user);

        // The alerts are queued notifications. Send them now, whatever the queue is.
        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            DB::transaction(fn () => $this->build($user));
        } finally {
            config(['queue.default' => $queue]);
        }

        $this->report(rebuilt: $user !== null);
    }

    /**
     * Each refusal throws before anything is written, so `db:seed` and
     * `migrate --seed` end with an error. They live in the seeder so that
     * `--force` and `--class=DemoSeeder` meet them too.
     */
    private function refuseWhereTheDemoDoesNotBelong(?User $demo): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(sprintf(
                'The demo adds the login %s with a password anyone can look up, so it does not run in production. To set up a real install, run `php artisan green:install`.',
                self::EMAIL,
            ));
        }

        // An install made from .env.example runs as "local", so the check above doesn't protect it.
        if (User::query()->where('email', '!=', self::EMAIL)->exists()) {
            throw new RuntimeException(sprintf(
                'This database already has a real login, so the demo was not loaded and nothing was changed. The demo adds %s with a password anyone can look up, and only loads into an empty database or one that holds nothing but the demo. To look at the demo, use a separate database, or run `php artisan migrate:fresh --seed`, which deletes everything in this one.',
                self::EMAIL,
            ));
        }

        if ($demo?->plaidItems()->whereNotLike('plaid_item_id', self::ITEM_PREFIX.'%')->exists()) {
            throw new RuntimeException(sprintf(
                '%s has a bank connection that did not come from the demo, and rebuilding the demo would delete it. Nothing was changed. To look at a fresh demo, use a separate database, or run `php artisan migrate:fresh --seed`, which deletes everything in this one.',
                self::EMAIL,
            ));
        }
    }

    private function build(?User $user): void
    {
        $this->random = new Randomizer(new Mt19937(20261021));
        $this->user = $this->login($user);

        $this->books();
        [$platypus, $houndstooth, $tattersall] = $this->banks();

        $this->personalRecurring();
        $this->businessRecurring();
        $this->statements();
        $this->settleBalances();

        foreach ([$platypus, $houndstooth] as $bank) {
            $this->finishSync($bank);
        }

        $this->pickCategories();
        $this->sendReminders();
        $this->plaidActivity($platypus, $houndstooth, $tattersall);
    }

    /**
     * The demo login, with nothing to its name. On a re-run that means
     * deleting what it owned, and only that: each delete goes through one of
     * the login's own relations, and the database cascades from there. The
     * login itself is kept as it is, so a changed name or password stays.
     */
    private function login(?User $user): User
    {
        if ($user === null) {
            return User::factory()->create(['name' => 'Test User', 'email' => self::EMAIL, 'password' => self::PASSWORD]);
        }

        $user->notifications()->delete();
        $user->recurrings()->delete();
        $user->plaidItems()->delete();
        $user->books()->delete();
        $user->categorySets()->delete();
        PlaidLog::query()->ownedBy($user)->delete();

        // Back to alerts in the app only, so the ones raised below can't go out as email.
        $user->forceFill(['notification_preferences' => null])->save();

        return $user;
    }

    /**
     * A business and a personal Book, each with its starter category set and
     * the payees the user has taught it.
     */
    private function books(): void
    {
        $this->business = Book::factory()->for($this->user)->business()->create(['name' => 'Acme Consulting LLC']);
        $this->personal = Book::factory()->for($this->user)->personal()->create(['name' => 'Personal']);

        app(StarterCategories::class)->createSetsFor($this->user);

        foreach ([$this->business, $this->personal] as $book) {
            $book->refresh();

            foreach (DemoStatements::PAYEES[$book->kind->value] as $category => $payees) {
                $this->category($book, $category)->update(['payees' => $payees]);
            }
        }
    }

    /**
     * Three bank logins. Cash accounts say what's available and cards what's
     * owed; `settleBalances()` works out the other balance from what's pending.
     *
     * @return array{PlaidItem, PlaidItem, PlaidItem}
     */
    private function banks(): array
    {
        // One login whose accounts sit in different Books.
        $platypus = PlaidItem::factory()->for($this->user)->synced(now()->startOfHour())->create([
            'plaid_item_id' => self::ITEM_PREFIX.'first-platypus-bank',
            'institution_id' => 'ins_109508',
            'institution_name' => 'First Platypus Bank',
        ]);

        // The Forecast page opens on the first cash account by name: this one.
        $this->accounts['everyday'] = Account::factory()->for($platypus)->create([
            'book_id' => $this->personal->id,
            'name' => 'Everyday Checking',
            'official_name' => 'First Platypus Everyday Checking',
            'mask' => '4242',
            'available_balance' => 2412.67,
        ]);

        // Overdrawn once the pending daycare charge is counted.
        $this->accounts['joint'] = Account::factory()->for($platypus)->create([
            'book_id' => $this->personal->id,
            'name' => 'Joint Checking',
            'official_name' => 'First Platypus Joint Checking',
            'mask' => '7310',
            'available_balance' => -46.80,
        ]);

        $this->accounts['business'] = Account::factory()->for($platypus)->create([
            'book_id' => $this->business->id,
            'name' => 'Small Business Checking',
            'official_name' => 'First Platypus Small Business Checking',
            'mask' => '0001',
            'available_balance' => 14286.40,
        ]);

        $houndstooth = PlaidItem::factory()->for($this->user)->synced(now()->startOfHour())->create([
            'plaid_item_id' => self::ITEM_PREFIX.'houndstooth-bank',
            'institution_id' => 'ins_109512',
            'institution_name' => 'Houndstooth Bank',
        ]);

        $this->accounts['visa'] = Account::factory()->for($houndstooth)->credit()->create([
            'book_id' => $this->personal->id,
            'name' => 'Rewards Visa',
            'official_name' => 'Houndstooth Cash Rewards Visa Signature',
            'mask' => '9921',
            'current_balance' => 1243.87,
            'credit_limit' => 8000,
        ]);

        $this->accounts['businessCard'] = Account::factory()->for($houndstooth)->credit()->create([
            'book_id' => $this->business->id,
            'name' => 'Business Card',
            'official_name' => 'Houndstooth Business Platinum Mastercard',
            'mask' => '3300',
            'current_balance' => 2318.40,
            'credit_limit' => 15000,
        ]);

        // Stopped syncing two days ago, when the credit union asked for a new sign-in.
        $tattersall = PlaidItem::factory()->for($this->user)->loginRequired()->synced(now()->subHours(49)->startOfHour())->create([
            'plaid_item_id' => self::ITEM_PREFIX.'tattersall-fcu',
            'institution_id' => 'ins_109510',
            'institution_name' => 'Tattersall Federal Credit Union',
            'error_message' => self::LOGIN_REQUIRED,
        ]);

        // Never put in a Book, so it shows as Unassigned and has no categories.
        $this->accounts['savings'] = Account::factory()->for($tattersall)->savings()->create([
            'name' => 'Share Savings',
            'official_name' => 'Tattersall Regular Share Savings',
            'mask' => '5521',
            'available_balance' => 6120.33,
            'balances_updated_at' => $tattersall->last_synced_at,
        ]);

        return [$platypus, $houndstooth, $tattersall];
    }

    /**
     * Everything recurring on Everyday Checking, timed so its forecast dips
     * before payday: about $2,400 today, $212.62 once rent is paid in six
     * days, then the paycheck three days later.
     */
    private function personalRecurring(): void
    {
        $paycheck = $this->income('everyday', 'Paycheck', 2250.00, dueIn: 9, frequency: Frequency::EveryTwoWeeks, payee: 'acme consulting', category: 'Paycheck');
        $this->payments($paycheck, 'ACME CONSULTING LLC OWNER DRAW', 'TRANSFER_IN_ACCOUNT_TRANSFER');

        // Paid by hand, a day or two early, with a reminder before every due date.
        $rent = $this->bill('everyday', 'Rent', 1650.00, dueIn: 6, payee: 'gumtree property', category: 'Rent or mortgage');
        $rent->reminders()->create(['days_before' => 7, 'repeats' => true, 'note' => 'Move the rent over from savings']);
        $this->payments($rent, 'GUMTREE PROPERTY MGMT WEB PMT', 'RENT_AND_UTILITIES_RENT', daysEarly: [2, 1], except: [1]);

        // One month's rent went by check. A check names no payee, so the user linked it.
        $byCheck = $this->pastDueDates($rent)->get(1);
        $byCheck?->link($this->transaction('everyday', $byCheck->due_on->subDay(), 'CHECK 1042', 1650.00), MatchSource::Manual);

        // The amount changes every month, so any amount from the payee matches.
        $electric = $this->bill('everyday', 'Electric', 96.00, dueIn: 3, payee: 'larkspur power', category: 'Utilities', autopay: true, anyAmount: true);
        $this->payments($electric, 'LARKSPUR POWER AND LIGHT AUTOPAY', 'RENT_AND_UTILITIES_GAS_AND_ELECTRICITY', amounts: [102.33, 88.47, 97.60]);

        // Due tomorrow and already pending, at a new price that is still within 10%.
        $internet = $this->bill('everyday', 'Comcast', 89.00, dueIn: 1, payee: 'comcast', category: 'Internet and phone', autopay: true);
        $this->payments($internet, 'COMCAST CABLE COMMUNICATIONS', 'RENT_AND_UTILITIES_INTERNET_AND_CABLE', 'Comcast');
        $this->transaction('everyday', 0, 'COMCAST CABLE COMMUNICATIONS', 91.20, 'RENT_AND_UTILITIES_INTERNET_AND_CABLE', 'Comcast', pending: true);

        // A one-off reminder that hasn't been sent yet.
        $netflix = $this->bill('everyday', 'Netflix', 15.49, dueIn: 10, payee: 'netflix', category: 'Streaming and subscriptions', autopay: true);
        $netflix->reminders()->create(['days_before' => 3, 'repeats' => false, 'note' => 'Cancel before it renews']);
        $this->payments($netflix, 'NETFLIX.COM', 'ENTERTAINMENT_TV_AND_MOVIES', 'Netflix');

        // Due three days ago and not paid: the overdue one.
        $water = $this->bill('everyday', 'Water and sewer', 64.00, dueIn: -3, payee: 'larkspur water', category: 'Utilities');
        $this->payments($water, 'CITY OF LARKSPUR WATER', 'RENT_AND_UTILITIES_WATER', except: [0]);

        // Every way a due date can be closed: this month's is postponed until
        // after payday, last month's was paid, the one before was paid in cash
        // and marked paid, and a later one is skipped.
        $piano = $this->bill('everyday', 'Piano lessons', 140.00, dueIn: 2, payee: 'allegro music', category: 'Education');
        $this->payments($piano, 'ZELLE TO ALLEGRO MUSIC STUDIO', 'TRANSFER_OUT_ACCOUNT_TRANSFER', except: [1]);
        $this->pastDueDates($piano)->get(1)?->update(['resolution' => OccurrenceResolution::Paid]);
        $piano->occurrences()->whereDate('due_on', today()->addDays(2))->sole()->update(['postponed_to' => today()->addDays(11)]);
        $piano->occurrences()->whereDate('due_on', '>', today()->addDays(45))->firstOrFail()->update(['resolution' => OccurrenceResolution::Skipped]);

        // One time, with no payee: the user links the payment when it shows up.
        $this->bill('everyday', 'Car repair', 640.00, dueIn: 13, frequency: Frequency::Once, anyAmount: true);

        // Quarterly and yearly bills show what to set aside each month.
        $carInsurance = $this->bill('everyday', 'Car insurance', 438.00, dueIn: 50, frequency: Frequency::EveryThreeMonths, payee: 'geico', category: 'Car insurance', autopay: true);
        $this->payments($carInsurance, 'GEICO AUTO PREMIUM', 'GENERAL_SERVICES_INSURANCE', 'GEICO');
        $this->bill('everyday', 'Renters insurance', 264.00, dueIn: 150, frequency: Frequency::Yearly, payee: 'lemonade insurance', category: 'Home insurance', autopay: true);

        // Paying the card is a bill on checking; the card's side is a payment received.
        $card = $this->bill('everyday', 'Rewards Visa payment', 450.00, dueIn: 16, payee: 'houndstooth card', category: 'Credit card payment', autopay: true, anyAmount: true);
        $this->payments($card, 'HOUNDSTOOTH CARD ONLINE PMT', 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT', amounts: [512.40, 388.15, 467.92], mirror: ['visa', 'PAYMENT THANK YOU - WEB']);

        // Flex spends count the purchases in `DemoStatements::BUDGETED`.
        // Groceries: $28.32 rolled over from four earlier weeks, and $84.62 of $188.32 spent so far.
        $this->flex('Groceries', 160.00, Frequency::Weekly, began: 4, earlierPeriods: 4, category: 'Groceries', rollsOver: true);
        // Eating out: earlier overspending left $104.50 for this period, and $118.30 is spent.
        $this->flex('Eating out', 120.00, Frequency::EveryTwoWeeks, began: 9, earlierPeriods: 2, category: 'Restaurants', rollsOver: true);
        // Fuel: resets each month, and $124.85 of $145.00 (86%) is spent. It began 16 days ago
        // so that its next month starts after payday, however long this month is.
        $this->flex('Fuel', 145.00, Frequency::Monthly, began: 16, earlierPeriods: 2, category: 'Fuel');
    }

    private function businessRecurring(): void
    {
        // Income, sometimes a couple of days late, with a reminder to invoice.
        $retainer = $this->income('business', 'Globex retainer', 6500.00, dueIn: 8, payee: 'globex', category: 'Sales and services');
        $retainer->reminders()->create(['days_before' => 10, 'repeats' => true, 'note' => 'Send Globex this month’s invoice']);
        $this->payments($retainer, 'GLOBEX CORPORATION ACH PMT', 'INCOME_OTHER', daysEarly: [0, -2, 0]);

        // The other side of the personal paycheck.
        $draw = $this->bill('business', 'Owner’s draw', 2250.00, dueIn: 9, frequency: Frequency::EveryTwoWeeks, payee: 'owner draw', category: 'Owner’s draw', autopay: true);
        $this->payments($draw, 'OWNER DRAW TO CHK ...4242', 'TRANSFER_OUT_ACCOUNT_TRANSFER');

        $wework = $this->bill('business', 'WeWork', 300.00, dueIn: 4, payee: 'wework', category: 'Business property', autopay: true);
        $this->payments($wework, 'WEWORK MEMBERSHIP', 'RENT_AND_UTILITIES_RENT', 'WeWork');

        // No category: its payments keep whatever category they'd get anyway.
        $tax = $this->bill('business', 'Estimated tax', 2400.00, dueIn: 52, frequency: Frequency::EveryThreeMonths, payee: 'irs usataxpymt');
        $this->payments($tax, 'IRS USATAXPYMT', 'GOVERNMENT_AND_NON_PROFIT_TAX_PAYMENT', daysEarly: [1]);
    }

    /**
     * Three months of what the banks reported, apart from the bill and income
     * payments added above.
     */
    private function statements(): void
    {
        foreach (DemoStatements::SPENDING as $account => $merchants) {
            foreach ($merchants as $row) {
                [$merchant, $plaidCategory, $lowest, $highest, $every] = $row;

                for ($daysAgo = $row[5] ?? $this->random->getInt(1, $every); $daysAgo <= self::DAYS; $daysAgo += max(1, $every + $this->random->getInt(-1, 1))) {
                    $cents = $this->random->getInt((int) round($lowest * 100), (int) round($highest * 100));

                    $this->transaction($account, $daysAgo, Str::upper($merchant), $cents / 100, $plaidCategory, $merchant);
                }
            }
        }

        foreach (DemoStatements::BUDGETED as [$daysAgo, $merchant, $plaidCategory, $amount]) {
            $this->transaction('everyday', $daysAgo, Str::upper($merchant), $amount, $plaidCategory, $merchant);
        }

        foreach (DemoStatements::OTHER as $row) {
            [$account, $daysAgo, $name, $amount, $plaidCategory] = $row;

            foreach ((array) $daysAgo as $day) {
                $this->transaction($account, $day, $name, $amount, $plaidCategory, $row[5] ?? null);
            }
        }

        foreach (DemoStatements::PENDING as [$account, $daysAgo, $merchant, $amount, $plaidCategory]) {
            $this->transaction($account, $daysAgo, Str::upper($merchant), $amount, $plaidCategory, $merchant, pending: true);
        }
    }

    /**
     * Make each account's balances agree with its pending transactions, as a
     * bank's do: a cash account's current balance is what's available plus
     * what's pending, and a card's available credit is its limit less what's
     * owed and pending.
     */
    private function settleBalances(): void
    {
        foreach ($this->accounts as $account) {
            $pending = (float) $account->transactions()->onlyPending()->sum('amount');

            $account->update($account->isCredit()
                ? ['available_balance' => round((float) $account->credit_limit - (float) $account->current_balance - $pending, 2)]
                : ['current_balance' => round((float) $account->available_balance + $pending, 2)]);
        }
    }

    /**
     * What `SyncPlaidItem` does once a sync has stored its pages, in the same
     * order: categorize, link bills and income to their payments, then alert
     * about negative balances and budgets.
     */
    private function finishSync(PlaidItem $bank): void
    {
        $accountIds = $bank->accounts()->pluck('id');

        app(CategorizeTransactions::class)->handle(Transaction::query()->whereIn('account_id', $accountIds));
        app(MatchRecurringTransactions::class)->forAccounts($accountIds);
        app(NotifyNegativeBalances::class)->forItem($bank);
        app(NotifyBudgetAlerts::class)->forAccounts($accountIds);
    }

    /**
     * Categories the user picked from the transaction list. Green never
     * replaces these.
     */
    private function pickCategories(): void
    {
        foreach (DemoStatements::PICKED as [$account, $name, $category]) {
            $account = $this->accounts[$account];

            $account->transactions()->where('name', $name)->latest('date')->firstOrFail()->update([
                'category_id' => $this->category($account->book, $category)->id,
                'category_source' => CategorySource::Manual,
            ]);
        }
    }

    /**
     * What `reminders:send` does each morning, for the demo's reminders only:
     * the command itself would send every other login's too.
     */
    private function sendReminders(): void
    {
        Reminder::query()->ownedBy($this->user)->with('recurring')->get()->each(function (Reminder $reminder): void {
            $dueDate = $reminder->dueOccurrence(today()->toImmutable());

            if ($dueDate === null) {
                return;
            }

            $reminder->repeats ? $reminder->update(['reminded_through' => $dueDate->expectedOn()]) : $reminder->delete();

            $this->user->notify(new RecurringReminder($reminder->recurring, $dueDate, $reminder->note));
        });
    }

    /**
     * Three days of the Plaid Activity page: hourly syncs, a Live balance, one
     * bank removed and another linked, and the sync that failed when the
     * credit union wanted a new sign-in, with the retry that failed too.
     */
    private function plaidActivity(PlaidItem $platypus, PlaidItem $houndstooth, PlaidItem $tattersall): void
    {
        $now = now()->toImmutable();

        $this->hourlySyncs($platypus, from: 71, until: 0);
        PlaidLog::factory()->for($platypus)->liveBalance()->create(['started_at' => $now->subMinutes(40)]);

        PlaidLog::factory()->for($this->user)->removal('Tartan Bank')->create(['started_at' => $now->subHours(60)]);

        $linkedAt = $now->subHours(47)->startOfHour()->addMinutes(18);
        PlaidLog::factory()->for($houndstooth)->link()->create(['started_at' => $linkedAt]);
        PlaidLog::factory()->for($houndstooth)->firstSync($houndstooth->transactions()->count())->create(['started_at' => $linkedAt->addSeconds(3)]);
        $this->hourlySyncs($houndstooth, from: 46, until: 0);

        $this->hourlySyncs($tattersall, from: 71, until: 49);
        PlaidLog::factory()->for($tattersall)->failed()->create(['started_at' => $now->subHours(48)->startOfHour(), 'error_message' => self::LOGIN_REQUIRED]);
        PlaidLog::factory()->for($tattersall)->failed()->manual()->create(['started_at' => $now->subHours(20), 'error_message' => self::LOGIN_REQUIRED]);
    }

    /**
     * The scheduler syncs every connected bank on the hour. Most syncs find
     * nothing new.
     */
    private function hourlySyncs(PlaidItem $bank, int $from, int $until): void
    {
        foreach (range($from, $until) as $hoursAgo) {
            PlaidLog::factory()->for($bank)->create([
                'started_at' => now()->subHours($hoursAgo)->startOfHour(),
                'added' => $this->random->getInt(1, 6) === 1 ? $this->random->getInt(1, 3) : 0,
                'modified' => $this->random->getInt(1, 8) === 1 ? 1 : 0,
            ]);
        }
    }

    private function bill(
        string $account,
        string $name,
        float $amount,
        int $dueIn,
        Frequency $frequency = Frequency::Monthly,
        ?string $payee = null,
        ?string $category = null,
        bool $autopay = false,
        bool $anyAmount = false,
    ): Recurring {
        return $this->recurring(
            Recurring::factory()
                ->when($autopay, fn (RecurringFactory $bill) => $bill->autopay())
                ->when($anyAmount, fn (RecurringFactory $bill) => $bill->anyAmount()),
            $account,
            $dueIn,
            ['name' => $name, 'amount' => $amount, 'frequency' => $frequency, 'payee' => $payee],
            $category,
        );
    }

    private function income(string $account, string $name, float $amount, int $dueIn, Frequency $frequency = Frequency::Monthly, ?string $payee = null, ?string $category = null): Recurring
    {
        return $this->recurring(
            Recurring::factory()->income(),
            $account,
            $dueIn,
            ['name' => $name, 'amount' => $amount, 'frequency' => $frequency, 'payee' => $payee],
            $category,
        );
    }

    /**
     * A budget on Everyday Checking whose current period began `$began` days
     * ago, after `$earlierPeriods` earlier ones.
     */
    private function flex(string $name, float $budget, Frequency $frequency, int $began, int $earlierPeriods, string $category, bool $rollsOver = false): Recurring
    {
        return $this->recurring(
            Recurring::factory()
                ->flex($this->category($this->personal, $category))
                ->when($rollsOver, fn (RecurringFactory $flex) => $flex->rollsOver()),
            'everyday',
            -$began,
            ['name' => $name, 'amount' => $budget, 'frequency' => $frequency],
            periods: $earlierPeriods,
        );
    }

    /**
     * A recurring item with a due date (or, for a flex spend, a period
     * starting) `$dueIn` days from today; negative is in the past. Its first
     * due date is earlier, as far back as the history goes or `$periods`
     * periods, and it was added to Green that day, so every due date since is
     * tracked. Creating it makes its due dates (`Recurring::booted()`).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recurring(RecurringFactory $factory, string $account, int $dueIn, array $attributes, ?string $category = null, ?int $periods = null): Recurring
    {
        $account = $this->accounts[$account];
        $startsOn = $this->firstDueDate($attributes['frequency'], today()->addDays($dueIn)->toImmutable(), $periods);

        return $factory->for($account)->create([
            ...$attributes,
            ...($category === null ? [] : ['category_id' => $this->category($account->book, $category)->id]),
            'starts_on' => $startsOn,
            'created_at' => $startsOn->min(now()),
        ]);
    }

    /**
     * The earliest date within the history (or within `$periods` periods)
     * whose schedule lands exactly on `$dueOn`. Month-end dates don't always
     * come back to the same day: a schedule that starts on Feb 28 never
     * reaches May 31. Those starts are passed over while there is another.
     * A quarterly bill has no other, so it starts there anyway and is due
     * up to three days sooner than asked.
     */
    private function firstDueDate(Frequency $frequency, CarbonImmutable $dueOn, ?int $periods = null): CarbonImmutable
    {
        if ($frequency === Frequency::Once) {
            return $dueOn;
        }

        $historyStarts = today()->subDays(self::DAYS);
        $exact = $inexact = null;

        for ($n = 1; $n <= ($periods ?? PHP_INT_MAX) && ($earlier = $frequency->nth($dueOn, -$n))->gte($historyStarts); $n++) {
            if ($frequency->nth($earlier, $n)->eq($dueOn)) {
                $exact = $earlier;
            } else {
                $inexact = $earlier;
            }
        }

        return $exact ?? $inexact ?? $dueOn;
    }

    /**
     * The bank's side of a bill or income: one transaction on its account for
     * each due date so far, which the sync then links. `$amounts` and
     * `$daysEarly` run from the latest due date back, and the last value
     * repeats; a negative number of days is a late payment. `$except` lists
     * the due dates (0 is the latest) with no payment. `$mirror` adds the
     * other side of a transfer: an account and the description there.
     *
     * @param  list<float>  $amounts
     * @param  list<int>  $daysEarly
     * @param  list<int>  $except
     * @param  array{string, string}|null  $mirror
     */
    private function payments(
        Recurring $recurring,
        string $name,
        string $plaidCategory,
        ?string $merchant = null,
        array $amounts = [],
        array $daysEarly = [0],
        array $except = [],
        ?array $mirror = null,
    ): void {
        foreach ($this->pastDueDates($recurring) as $index => $dueDate) {
            if (in_array($index, $except, true)) {
                continue;
            }

            $paidOn = $dueDate->due_on->subDays($daysEarly[$index] ?? end($daysEarly));
            $amount = ($amounts[$index] ?? (end($amounts) ?: (float) $recurring->amount)) * ($recurring->kind->isOutflow() ? 1 : -1);

            $this->transaction($recurring->account, $paidOn, $name, $amount, $plaidCategory, $merchant);

            if ($mirror !== null) {
                $this->transaction($mirror[0], $paidOn, $mirror[1], -$amount, $plaidCategory);
            }
        }
    }

    /**
     * @return Collection<int, Occurrence> the latest first
     */
    private function pastDueDates(Recurring $recurring): Collection
    {
        return $recurring->occurrences()->whereDate('due_on', '<', today())->reorder('due_on', 'desc')->get();
    }

    /**
     * One transaction as a sync stores it: positive amounts are money out.
     *
     * @param  CarbonInterface|int  $on  A date, or how many days ago.
     */
    private function transaction(
        Account|string $account,
        CarbonInterface|int $on,
        string $name,
        float $amount,
        ?string $plaidCategory = null,
        ?string $merchant = null,
        bool $pending = false,
    ): Transaction {
        return Transaction::factory()
            ->for(is_string($account) ? $this->accounts[$account] : $account)
            ->on(is_int($on) ? today()->subDays($on) : $on)
            ->plaidCategory($plaidCategory)
            ->create(['name' => $name, 'merchant_name' => $merchant, 'amount' => $amount, 'pending' => $pending]);
    }

    private function category(Book $book, string $name): Category
    {
        return $book->categorySet->categories()->where('name', $name)->sole();
    }

    private function report(bool $rebuilt): void
    {
        $this->say('info', $rebuilt
            ? sprintf('Rebuilt the demo. Everything %s owned was replaced with a fresh copy.', self::EMAIL)
            : 'Loaded the demo.');

        $this->say('bulletList', [
            $rebuilt
                ? sprintf('Sign in as %s. Its password was left as it was: "%s", unless it was changed', self::EMAIL, self::PASSWORD)
                : sprintf('Sign in as %s / %s', self::EMAIL, self::PASSWORD),
            sprintf(
                '%d Books, %d bank connections, %d accounts, %d transactions and %d recurring items',
                $this->user->books()->count(),
                $this->user->plaidItems()->count(),
                count($this->accounts),
                Transaction::query()->ownedBy($this->user)->count(),
                $this->user->recurrings()->count(),
            ),
        ]);
    }

    /**
     * @param  string|list<string>  $message
     */
    private function say(string $style, string|array $message): void
    {
        $this->command?->outputComponents()->{$style}($message);
    }
}
