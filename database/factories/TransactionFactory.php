<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-60 days');

        return [
            'account_id' => Account::factory(),
            'plaid_transaction_id' => Str::random(37),
            'pending_plaid_transaction_id' => null,
            'amount' => fake()->randomFloat(2, 1, 250),
            'currency' => 'USD',
            'date' => $date,
            'authorized_date' => $date,
            'name' => fake()->company(),
            'merchant_name' => fake()->company(),
            'pending' => false,
            'category_primary' => 'GENERAL_MERCHANDISE',
            'category_detailed' => 'GENERAL_MERCHANDISE_OTHER_GENERAL_MERCHANDISE',
            'payment_channel' => 'in store',
            'raw' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(function () {
            $date = fake()->dateTimeBetween('-3 days');

            return ['pending' => true, 'date' => $date, 'authorized_date' => $date];
        });
    }

    public function inflow(): static
    {
        return $this->state(fn () => ['amount' => -1 * fake()->randomFloat(2, 100, 5000)]);
    }
}
