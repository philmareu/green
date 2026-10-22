<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Plaid API fixtures
|--------------------------------------------------------------------------
|
| Shapes follow https://plaid.com/docs/api/. Only the fields Green reads are
| included.
|
*/

const PLAID = 'https://sandbox.plaid.com';

function plaidAccount(array $overrides = []): array
{
    return array_replace_recursive([
        'account_id' => 'acc-checking',
        'name' => 'Plaid Checking',
        'official_name' => 'Plaid Gold Standard 0% Interest Checking',
        'mask' => '0000',
        'type' => 'depository',
        'subtype' => 'checking',
        'balances' => [
            'available' => 100.00,
            'current' => 110.00,
            'limit' => null,
            'iso_currency_code' => 'USD',
            'unofficial_currency_code' => null,
        ],
    ], $overrides);
}

function plaidTransaction(array $overrides = []): array
{
    return array_replace([
        'transaction_id' => 'tx-1',
        'account_id' => 'acc-checking',
        'amount' => 12.50,
        'iso_currency_code' => 'USD',
        'unofficial_currency_code' => null,
        'date' => '2026-10-20',
        'authorized_date' => '2026-10-19',
        'name' => 'STARBUCKS STORE 1234',
        'merchant_name' => 'Starbucks',
        'pending' => false,
        'pending_transaction_id' => null,
        'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_COFFEE'],
        'payment_channel' => 'in store',
    ], $overrides);
}

/**
 * One /transactions/sync response page.
 */
function syncPage(
    array $added = [],
    array $modified = [],
    array $removed = [],
    bool $hasMore = false,
    string $nextCursor = 'cursor-final',
    ?array $accounts = null,
    string $status = 'HISTORICAL_UPDATE_COMPLETE',
): array {
    return [
        'added' => $added,
        'modified' => $modified,
        'removed' => array_map(fn (string $id) => ['transaction_id' => $id, 'account_id' => 'acc-checking'], $removed),
        'accounts' => $accounts ?? [plaidAccount()],
        'has_more' => $hasMore,
        'next_cursor' => $nextCursor,
        'transactions_update_status' => $status,
        'request_id' => 'req-sync',
    ];
}

function plaidError(string $code, string $type = 'ITEM_ERROR', int $status = 400, ?string $displayMessage = null): array
{
    return [
        'body' => [
            'error_type' => $type,
            'error_code' => $code,
            'error_message' => "Plaid error {$code}",
            'display_message' => $displayMessage,
            'request_id' => 'req-error',
        ],
        'status' => $status,
    ];
}
