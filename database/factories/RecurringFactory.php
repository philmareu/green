<?php

namespace Database\Factories;

use App\Enums\Frequency;
use App\Enums\RecurringKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Recurring;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recurring>
 */
class RecurringFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            // The account owner, so ownership lines up.
            'user_id' => fn (array $attributes) => Account::query()->find($attributes['account_id'])?->plaidItem->user_id ?? User::factory(),
            'kind' => RecurringKind::Bill,
            'name' => 'Comcast',
            'amount' => 89.00,
            'frequency' => Frequency::Monthly,
            'starts_on' => today(),
            'ends_on' => null,
            'payee' => 'comcast',
            'amount_tolerance' => 10,
            'category_id' => null,
            'dismissed_transactions' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(fn () => ['kind' => RecurringKind::Income, 'name' => 'Retainer', 'payee' => 'acme', 'amount' => 2500]);
    }

    public function flex(Category $category): static
    {
        return $this->state(fn () => [
            'kind' => RecurringKind::Flex,
            'name' => $category->name,
            'payee' => null,
            'amount_tolerance' => null,
            'frequency' => Frequency::Weekly,
            'category_id' => $category->getKey(),
        ]);
    }
}
