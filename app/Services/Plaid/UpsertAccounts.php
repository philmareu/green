<?php

namespace App\Services\Plaid;

use App\Models\Account;
use App\Models\Book;
use App\Models\PlaidItem;

/**
 * Creates or updates local accounts from Plaid account objects. Balances and
 * bank metadata always refresh; a name the user has edited is kept.
 */
class UpsertAccounts
{
    /**
     * @param  list<array<string, mixed>>  $plaidAccounts
     */
    public function handle(PlaidItem $item, array $plaidAccounts, ?Book $book = null): void
    {
        foreach ($plaidAccounts as $plaidAccount) {
            $account = Account::query()->firstOrNew([
                'plaid_item_id' => $item->getKey(),
                'plaid_account_id' => $plaidAccount['account_id'],
            ]);

            $balances = $plaidAccount['balances'] ?? [];

            if (! $account->exists) {
                $account->name = $plaidAccount['name'] ?? 'Account';
                $account->book_id = $book?->getKey();
            }

            $account->fill([
                'official_name' => $plaidAccount['official_name'] ?? null,
                'mask' => $plaidAccount['mask'] ?? null,
                'type' => $plaidAccount['type'] ?? null,
                'subtype' => $plaidAccount['subtype'] ?? null,
                'currency' => $balances['iso_currency_code'] ?? $balances['unofficial_currency_code'] ?? 'USD',
                'current_balance' => $balances['current'] ?? null,
                'available_balance' => $balances['available'] ?? null,
                'credit_limit' => $balances['limit'] ?? null,
                'balances_updated_at' => now(),
            ])->save();
        }
    }
}
