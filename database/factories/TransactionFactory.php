<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\Categories\PlaidCategories;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;

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

    /**
     * Authorized and dated the same day.
     */
    public function on(CarbonInterface $date): static
    {
        return $this->state(fn () => ['date' => $date, 'authorized_date' => $date]);
    }

    /**
     * Plaid's category, from one of its codes: a detailed code such as
     * "FOOD_AND_DRINK_COFFEE" also sets its primary, "FOOD_AND_DRINK". Null
     * is a transaction Plaid couldn't categorize.
     */
    public function plaidCategory(?string $code): static
    {
        if ($code === null) {
            return $this->state(fn () => ['category_primary' => null, 'category_detailed' => null]);
        }

        static $primaries;

        // Every code Plaid has, mapped to its primary code.
        $primaries ??= collect(PlaidCategories::options())
            ->flatMap(fn (array $codes): array => array_fill_keys(array_keys($codes), array_key_first($codes)))
            ->all();

        $primary = $primaries[$code] ?? throw new InvalidArgumentException("Unknown Plaid category [{$code}].");

        return $this->state(fn () => [
            'category_primary' => $primary,
            'category_detailed' => $code === $primary ? null : $code,
        ]);
    }
}
