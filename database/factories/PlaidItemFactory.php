<?php

namespace Database\Factories;

use App\Enums\PlaidItemStatus;
use App\Models\PlaidItem;
use App\Models\User;
use Carbon\CarbonInterface;
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

    /**
     * Synced at least once, with its transaction history fully loaded.
     */
    public function synced(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => [
            'last_synced_at' => $at ?? now(),
            'transactions_update_status' => 'HISTORICAL_UPDATE_COMPLETE',
        ]);
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
