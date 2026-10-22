<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Book;
use App\Models\PlaidItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        $current = fake()->randomFloat(2, 100, 20000);

        return [
            'plaid_item_id' => PlaidItem::factory(),
            'book_id' => null,
            'plaid_account_id' => Str::random(37),
            'name' => 'Plaid Checking',
            'official_name' => 'Plaid Gold Standard 0% Interest Checking',
            'mask' => (string) fake()->numberBetween(1000, 9999),
            'type' => 'depository',
            'subtype' => 'checking',
            'currency' => 'USD',
            'current_balance' => $current,
            'available_balance' => $current - fake()->randomFloat(2, 0, 100),
            'credit_limit' => null,
            'balances_updated_at' => now(),
            'is_hidden' => false,
        ];
    }

    /**
     * Assign to a Book, using the Book owner's Plaid Item so ownership lines up.
     */
    public function inBook(Book $book): static
    {
        return $this->state(fn () => [
            'book_id' => $book->getKey(),
            'plaid_item_id' => PlaidItem::factory()->for($book->user),
        ]);
    }

    public function credit(): static
    {
        return $this->state(fn () => [
            'name' => 'Plaid Credit Card',
            'official_name' => 'Plaid Diamond 12.5% APR Interest Credit Card',
            'type' => 'credit',
            'subtype' => 'credit card',
            'current_balance' => 410.00,
            'available_balance' => 1590.00,
            'credit_limit' => 2000.00,
        ]);
    }
}
