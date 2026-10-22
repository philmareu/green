<?php

namespace App\Services\Plaid;

use App\Models\PlaidItem;

/**
 * Fetches real-time balances straight from the bank. In production Plaid bills
 * this per call, so it only runs when a user asks for it.
 */
class RefreshLiveBalances
{
    public function __construct(
        private readonly PlaidClient $plaid,
        private readonly UpsertAccounts $upsertAccounts,
    ) {}

    /**
     * @param  list<string>  $plaidAccountIds  Limit the refresh to these accounts.
     */
    public function handle(PlaidItem $item, array $plaidAccountIds = []): void
    {
        $response = $this->plaid->getBalances($item->access_token, $plaidAccountIds);

        $this->upsertAccounts->handle($item, $response['accounts']);
    }
}
