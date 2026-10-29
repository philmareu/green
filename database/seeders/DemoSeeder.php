<?php

namespace Database\Seeders;

use App\Enums\Frequency;
use App\Enums\PlaidItemStatus;
use App\Enums\RecurringKind;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\PlaidItem;
use App\Models\PlaidLog;
use App\Models\Recurring;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Categories\CategorizeTransactions;
use App\Services\Categories\StarterCategories;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sample Books, accounts and transactions for trying the UI without Plaid keys.
 * Nothing here talks to Plaid, so "Sync now" on these accounts will fail.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    /** @var array<string, list<array{0: string, 1: string, 2: float, 3: float, 4?: string}>> merchant, category, min, max, detailed category */
    private const array MERCHANTS = [
        'business' => [
            ['GitHub', 'GENERAL_SERVICES', 4, 21],
            ['Amazon Web Services', 'GENERAL_SERVICES', 38, 140],
            ['Adobe', 'GENERAL_SERVICES', 22, 60],
            ['Delta Air Lines', 'TRAVEL', 180, 520],
            ['WeWork', 'RENT_AND_UTILITIES', 300, 300],
            ['Laracasts', 'GENERAL_SERVICES', 15, 15],
            ['Staples', 'GENERAL_MERCHANDISE', 12, 90],
        ],
        'personal' => [
            ['Whole Foods Market', 'FOOD_AND_DRINK', 24, 180, 'FOOD_AND_DRINK_GROCERIES'],
            ['Starbucks', 'FOOD_AND_DRINK', 4, 12],
            ['Shell', 'TRANSPORTATION', 30, 70, 'TRANSPORTATION_GAS'],
            ['Comcast', 'RENT_AND_UTILITIES', 89, 89],
            ['Target', 'GENERAL_MERCHANDISE', 15, 140],
            ['Netflix', 'ENTERTAINMENT', 15.49, 15.49],
            ['Chipotle', 'FOOD_AND_DRINK', 11, 28],
        ],
    ];

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $user = User::query()->where('email', 'test@example.com')->firstOrFail();

        $business = Book::factory()->for($user)->business()->create(['name' => 'Acme Consulting LLC']);
        $personal = Book::factory()->for($user)->personal()->create(['name' => 'Personal']);

        app(StarterCategories::class)->createSetsFor($user);

        // One bank login holding both a business and a personal account.
        $bank = PlaidItem::factory()->for($user)->create([
            'institution_name' => 'First Platypus Bank',
            'last_synced_at' => now()->subMinutes(12),
            'transactions_update_status' => 'HISTORICAL_UPDATE_COMPLETE',
        ]);

        $businessChecking = Account::factory()->for($bank)->create([
            'book_id' => $business->id,
            'name' => 'Business Checking',
            'mask' => '0001',
            'current_balance' => 18450.12,
            'available_balance' => 17212.40,
        ]);

        $personalChecking = Account::factory()->for($bank)->create([
            'book_id' => $personal->id,
            'name' => 'Personal Checking',
            'mask' => '4242',
            'current_balance' => 3120.55,
            'available_balance' => 2987.10,
        ]);

        $card = PlaidItem::factory()->for($user)->create([
            'institution_name' => 'Tattersall Federal Credit Union',
            'status' => PlaidItemStatus::LoginRequired,
            'error_code' => 'ITEM_LOGIN_REQUIRED',
            'error_message' => 'Your bank needs you to sign in again.',
            'last_synced_at' => now()->subDays(2),
            'transactions_update_status' => 'HISTORICAL_UPDATE_COMPLETE',
        ]);

        $personalCard = Account::factory()->for($card)->credit()->create([
            'book_id' => $personal->id,
            'name' => 'Rewards Visa',
            'mask' => '9921',
        ]);

        $this->spending($businessChecking, 'business', posted: 22, pending: 2);
        $this->spending($personalChecking, 'personal', posted: 18, pending: 2);
        $this->spending($personalCard, 'personal', posted: 20, pending: 3);

        foreach ([3, 33] as $daysAgo) {
            $this->income($businessChecking, 'Client payment: Invoice '.random_int(1000, 1099), 8500, $daysAgo);
        }

        $this->income($personalChecking, 'Owner draw from Acme Consulting', 2000, 2, plaidCategory: 'TRANSFER_IN');

        app(CategorizeTransactions::class)->handle(Transaction::query()->ownedBy($user));

        $this->recurring($user, $businessChecking, $personalChecking);
        $this->plaidActivity($bank, $card);
    }

    /**
     * A day of hourly syncs, a Live balance refresh, and the failed sync that
     * left the card needing a reconnect.
     */
    private function plaidActivity(PlaidItem $bank, PlaidItem $card): void
    {
        foreach (range(1, 24) as $hoursAgo) {
            PlaidLog::factory()->for($bank)->create(['started_at' => now()->subHours($hoursAgo)->startOfHour()->addSeconds(4)]);
        }

        PlaidLog::factory()->for($bank)->liveBalance()->create(['started_at' => now()->subMinutes(40)]);
        PlaidLog::factory()->for($card)->failed()->create(['started_at' => $card->last_synced_at->addHour()]);
    }

    /**
     * Bills, flex spends and income so the forecast has something to show.
     */
    private function recurring(User $user, Account $businessChecking, Account $personalChecking): void
    {
        $category = fn (string $name): ?int => Category::query()->ownedBy($user)->where('name', $name)->value('id');
        $monthly = fn (int $dueInDays) => today()->addDays($dueInDays)->subMonths(3);

        $items = [
            [$personalChecking, RecurringKind::Bill, 'Rent', 1850, Frequency::Monthly, today()->addMonthNoOverflow()->startOfMonth(), []],
            [$personalChecking, RecurringKind::Bill, 'Comcast', 89, Frequency::Monthly, $monthly(5), ['payee' => 'comcast']],
            [$personalChecking, RecurringKind::Bill, 'Netflix', 15.49, Frequency::Monthly, $monthly(12), ['payee' => 'netflix']],
            [$personalChecking, RecurringKind::Flex, 'Groceries', 150, Frequency::Weekly, today()->startOfWeek(), ['category_id' => $category('Groceries')]],
            [$personalChecking, RecurringKind::Flex, 'Gas', 60, Frequency::Weekly, today()->startOfWeek(), ['category_id' => $category('Fuel')]],
            [$personalChecking, RecurringKind::Income, 'Owner draw', 2000, Frequency::EveryTwoWeeks, today()->subDays(2), ['payee' => 'owner draw']],
            [$personalChecking, RecurringKind::Bill, 'Car repair', 800, Frequency::Once, today()->addDays(9), []],
            [$businessChecking, RecurringKind::Income, 'Client retainer', 8500, Frequency::Monthly, today()->subDays(33), ['payee' => 'client payment', 'amount_tolerance' => null]],
        ];

        foreach ($items as [$account, $kind, $name, $amount, $frequency, $startsOn, $attributes]) {
            Recurring::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'kind' => $kind,
                'name' => $name,
                'amount' => $amount,
                'frequency' => $frequency,
                'starts_on' => $startsOn,
                'amount_tolerance' => $kind === RecurringKind::Flex ? null : 10,
                ...$attributes,
            ]);
        }
    }

    private function spending(Account $account, string $kind, int $posted, int $pending): void
    {
        foreach (range(1, $posted + $pending) as $i) {
            [$merchant, $category, $min, $max, $detailed] = [...fake()->randomElement(self::MERCHANTS[$kind]), null];
            $isPending = $i <= $pending;
            $date = $isPending ? now()->subDays(random_int(0, 2)) : now()->subDays(random_int(3, 60));

            Transaction::factory()->for($account)->create([
                'name' => Str::upper($merchant).' #'.random_int(100, 999),
                'merchant_name' => $merchant,
                'amount' => round(fake()->randomFloat(2, $min, $max), 2),
                'pending' => $isPending,
                'date' => $date,
                'authorized_date' => $date,
                'category_primary' => $category,
                'category_detailed' => $detailed,
            ]);
        }
    }

    private function income(Account $account, string $name, float $amount, int $daysAgo, string $plaidCategory = 'INCOME'): void
    {
        Transaction::factory()->for($account)->create([
            'name' => $name,
            'merchant_name' => null,
            'amount' => -$amount,
            'date' => now()->subDays($daysAgo),
            'authorized_date' => now()->subDays($daysAgo),
            'category_primary' => $plaidCategory,
            'category_detailed' => null,
            'payment_channel' => 'other',
        ]);
    }
}
