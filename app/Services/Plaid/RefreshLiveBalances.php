<?php

namespace App\Services\Plaid;

use App\Enums\PlaidLogKind;
use App\Models\PlaidItem;

/**
 * Fetches real-time balances straight from the bank. In production Plaid bills
 * this per call, so it only runs when a user asks for it, and each call is
 * logged.
 */
class RefreshLiveBalances
{
    public function __construct(
        private readonly PlaidClient $plaid,
        private readonly UpsertAccounts $upsertAccounts,
        private readonly PlaidLogger $logger,
    ) {}

    /**
     * @param  list<string>  $plaidAccountIds  Limit the refresh to these accounts.
     */
    public function handle(PlaidItem $item, array $plaidAccountIds = []): void
    {
        $this->logger->record(PlaidLogKind::LiveBalance, $item, function () use ($item, $plaidAccountIds): void {
            $response = $this->plaid->getBalances($item->access_token, $plaidAccountIds);

            $this->upsertAccounts->handle($item, $response['accounts']);
        });
    }
}
