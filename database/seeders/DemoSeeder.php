<?php

namespace Database\Seeders;

use App\Enums\PlaidItemStatus;
use App\Models\Account;
use App\Models\Book;
use App\Models\PlaidItem;
use App\Models\Transaction;
use App\Models\User;
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
    /** @var array<string, list<array{0: string, 1: string, 2: float, 3: float}>> merchant, category, min, max */
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
            ['Whole Foods Market', 'FOOD_AND_DRINK', 24, 180],
            ['Starbucks', 'FOOD_AND_DRINK', 4, 12],
            ['Shell', 'TRANSPORTATION', 30, 70],
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

        $this->income($personalChecking, 'Owner draw from Acme Consulting', 4000, 2);
    }

    private function spending(Account $account, string $kind, int $posted, int $pending): void
    {
        foreach (range(1, $posted + $pending) as $i) {
            [$merchant, $category, $min, $max] = fake()->randomElement(self::MERCHANTS[$kind]);
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
                'category_detailed' => null,
            ]);
        }
    }

    private function income(Account $account, string $name, float $amount, int $daysAgo): void
    {
        Transaction::factory()->for($account)->create([
            'name' => $name,
            'merchant_name' => null,
            'amount' => -$amount,
            'date' => now()->subDays($daysAgo),
            'authorized_date' => now()->subDays($daysAgo),
            'category_primary' => 'INCOME',
            'category_detailed' => null,
            'payment_channel' => 'other',
        ]);
    }
}
