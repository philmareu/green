<?php

namespace Database\Factories;

use App\Enums\PlaidItemStatus;
use App\Models\PlaidItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlaidItem>
 */
class PlaidItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plaid_item_id' => Str::random(37),
            'access_token' => 'access-sandbox-'.Str::uuid(),
            'institution_id' => 'ins_109508',
            'institution_name' => 'First Platypus Bank',
            'status' => PlaidItemStatus::Active,
        ];
    }

    public function loginRequired(): static
    {
        return $this->state([
            'status' => PlaidItemStatus::LoginRequired,
            'error_code' => 'ITEM_LOGIN_REQUIRED',
            'error_message' => 'The login details of this item have changed.',
        ]);
    }
}
