<?php

namespace App\Services\Plaid;

use App\Models\PlaidItem;

/**
 * Revokes the Item at Plaid (which stops billing and data access), then deletes
 * it locally along with its accounts and transactions.
 */
class RemovePlaidItem
{
    /** Errors meaning Plaid has already forgotten the Item. */
    private const array ALREADY_GONE = ['ITEM_NOT_FOUND', 'INVALID_ACCESS_TOKEN'];

    public function __construct(private readonly PlaidClient $plaid) {}

    public function handle(PlaidItem $item): void
    {
        try {
            $this->plaid->removeItem($item->access_token);
        } catch (PlaidException $e) {
            if (! in_array($e->errorCode, self::ALREADY_GONE, true)) {
                throw $e;
            }
        }

        $item->delete();
    }
}
