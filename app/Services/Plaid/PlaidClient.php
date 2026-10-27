<?php

namespace App\Services\Plaid;

use App\Models\PlaidItem;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * A thin client for the handful of Plaid endpoints Green needs.
 *
 * Plaid has no official PHP SDK, so this talks to the REST API directly.
 *
 * @see https://plaid.com/docs/api/
 */
class PlaidClient
{
    public const string API_VERSION = '2020-09-14';

    /**
     * @param  array<string, mixed>  $config  The `services.plaid` config array.
     */
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return filled($this->config['client_id'] ?? null) && filled($this->config['secret'] ?? null);
    }

    public function environment(): string
    {
        return $this->config['env'] ?? 'sandbox';
    }

    public function isSandbox(): bool
    {
        return $this->environment() === 'sandbox';
    }

    /**
     * Create a Link token. Pass an existing item to launch Link in update mode,
     * which is how a user re-authenticates an expired bank connection.
     *
     * @return array{link_token: string, expiration: string, request_id: string}
     */
    public function createLinkToken(User $user, ?PlaidItem $forUpdate = null): array
    {
        $payload = [
            'client_name' => mb_substr((string) $this->config['client_name'], 0, 30),
            'language' => $this->config['language'],
            'country_codes' => array_values($this->config['country_codes']),
            'user' => ['client_user_id' => (string) $user->getKey()],
        ];

        if ($forUpdate) {
            $payload['access_token'] = $forUpdate->access_token;
        } else {
            $payload['products'] = array_values($this->config['products']);
            $payload['transactions'] = ['days_requested' => $this->config['days_requested']];
        }

        if (filled($this->config['redirect_uri'] ?? null)) {
            $payload['redirect_uri'] = $this->config['redirect_uri'];
        }

        return $this->post('/link/token/create', $payload);
    }

    /**
     * @return array{access_token: string, item_id: string, request_id: string}
     */
    public function exchangePublicToken(string $publicToken): array
    {
        return $this->post('/item/public_token/exchange', ['public_token' => $publicToken]);
    }

    /**
     * Accounts with cached balances. Free to call.
     *
     * @return array{accounts: list<array<string, mixed>>, item: array<string, mixed>}
     */
    public function getAccounts(string $accessToken): array
    {
        return $this->post('/accounts/get', ['access_token' => $accessToken]);
    }

    /**
     * Real-time balances fetched from the bank. Billed per call in production.
     *
     * @param  list<string>  $accountIds
     * @return array{accounts: list<array<string, mixed>>, item: array<string, mixed>}
     */
    public function getBalances(string $accessToken, array $accountIds = []): array
    {
        $payload = ['access_token' => $accessToken];

        if ($accountIds !== []) {
            $payload['options'] = ['account_ids' => array_values($accountIds)];
        }

        return $this->post('/accounts/balance/get', $payload);
    }

    /**
     * One page of incremental transaction updates.
     *
     * @return array{added: list<array<string, mixed>>, modified: list<array<string, mixed>>, removed: list<array{transaction_id: string}>, accounts: list<array<string, mixed>>, next_cursor: string, has_more: bool, transactions_update_status?: string}
     */
    public function syncTransactions(string $accessToken, ?string $cursor = null, int $count = 500): array
    {
        $payload = [
            'access_token' => $accessToken,
            'count' => $count,
            // Older Plaid accounts default to v1 categories; Green's suggestions use v2.
            'options' => ['personal_finance_category_version' => 'v2'],
        ];

        if (filled($cursor)) {
            $payload['cursor'] = $cursor;
        }

        return $this->post('/transactions/sync', $payload);
    }

    /**
     * @return array{item: array<string, mixed>}
     */
    public function getItem(string $accessToken): array
    {
        return $this->post('/item/get', ['access_token' => $accessToken]);
    }

    /**
     * @return array{institution: array{institution_id: string, name: string}}
     */
    public function getInstitution(string $institutionId): array
    {
        return $this->post('/institutions/get_by_id', [
            'institution_id' => $institutionId,
            'country_codes' => array_values($this->config['country_codes']),
        ]);
    }

    public function removeItem(string $accessToken): array
    {
        return $this->post('/item/remove', ['access_token' => $accessToken]);
    }

    /**
     * Create an Item in the Sandbox without going through the Link UI.
     *
     * `user_transactions_dynamic` produces realistic, changing transactions,
     * including pending ones that later post.
     *
     * @return array{public_token: string, request_id: string}
     */
    public function sandboxCreatePublicToken(
        string $institutionId = 'ins_109508',
        string $username = 'user_transactions_dynamic',
    ): array {
        if (! $this->isSandbox()) {
            throw new InvalidArgumentException('Sandbox endpoints are only available when PLAID_ENV=sandbox.');
        }

        return $this->post('/sandbox/public_token/create', [
            'institution_id' => $institutionId,
            'initial_products' => array_values($this->config['products']),
            'options' => [
                'override_username' => $username,
                'override_password' => 'pass_good',
                'transactions' => ['days_requested' => $this->config['days_requested']],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(string $endpoint, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw PlaidException::notConfigured();
        }

        try {
            $response = $this->http()->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            throw PlaidException::connectionFailed($e);
        }

        if ($response->failed()) {
            throw PlaidException::fromResponse($response);
        }

        return $response->json();
    }

    protected function http(): PendingRequest
    {
        $environment = $this->environment();
        $baseUrl = $this->config['base_urls'][$environment]
            ?? throw new InvalidArgumentException("Unknown PLAID_ENV [{$environment}]. Use \"sandbox\" or \"production\".");

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->config['timeout'] ?? 60)
            ->withHeaders([
                'PLAID-CLIENT-ID' => $this->config['client_id'],
                'PLAID-SECRET' => $this->config['secret'],
                'Plaid-Version' => self::API_VERSION,
            ]);
    }
}
