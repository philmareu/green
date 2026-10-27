<?php

use App\Models\PlaidItem;
use App\Models\User;
use App\Services\Plaid\PlaidClient;
use App\Services\Plaid\PlaidException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function freshPlaidClient(): PlaidClient
{
    app()->forgetInstance(PlaidClient::class);

    return app(PlaidClient::class);
}

it('creates a link token for a new connection', function () {
    Http::fake([PLAID.'/link/token/create' => Http::response(['link_token' => 'link-sandbox-1', 'expiration' => now()->addHours(4)->toIso8601String(), 'request_id' => 'r'])]);
    $user = User::factory()->create();

    $response = app(PlaidClient::class)->createLinkToken($user);

    expect($response['link_token'])->toBe('link-sandbox-1');

    Http::assertSent(fn (Request $request) => $request->url() === PLAID.'/link/token/create'
        && $request->hasHeader('PLAID-CLIENT-ID', 'test-client-id')
        && $request->hasHeader('PLAID-SECRET', 'test-secret')
        && $request->hasHeader('Plaid-Version', PlaidClient::API_VERSION)
        && $request['user'] === ['client_user_id' => (string) $user->id]
        && $request['products'] === ['transactions']
        && $request['country_codes'] === ['US']
        && $request['transactions'] === ['days_requested' => 90]
        && ! isset($request['access_token'])
        && ! isset($request['redirect_uri']));
});

it('creates an update-mode link token for an existing item', function () {
    Http::fake([PLAID.'/link/token/create' => Http::response(['link_token' => 'link-update', 'expiration' => '', 'request_id' => 'r'])]);
    $item = PlaidItem::factory()->create(['access_token' => 'access-sandbox-abc']);

    app(PlaidClient::class)->createLinkToken($item->user, $item);

    Http::assertSent(fn (Request $request) => $request['access_token'] === 'access-sandbox-abc'
        && ! isset($request['products']));
});

it('sends the OAuth redirect uri when configured', function () {
    config()->set('services.plaid.redirect_uri', 'https://green.test/plaid/oauth');
    Http::fake([PLAID.'/link/token/create' => Http::response(['link_token' => 'x', 'expiration' => '', 'request_id' => 'r'])]);

    freshPlaidClient()->createLinkToken(User::factory()->create());

    Http::assertSent(fn (Request $request) => $request['redirect_uri'] === 'https://green.test/plaid/oauth');
});

it('only sends a cursor when there is one', function () {
    Http::fake([PLAID.'/transactions/sync' => Http::response(syncPage())]);
    $plaid = app(PlaidClient::class);

    $plaid->syncTransactions('access-1', null);
    $plaid->syncTransactions('access-1', 'cursor-abc');

    $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
    expect(isset($requests[0]['cursor']))->toBeFalse()
        ->and($requests[1]['cursor'])->toBe('cursor-abc')
        ->and($requests[1]['count'])->toBe(500)
        ->and($requests[1]['options']['personal_finance_category_version'])->toBe('v2');
});

it('turns Plaid error responses into exceptions', function () {
    Http::fake([PLAID.'/accounts/get' => Http::response(...plaidError('ITEM_LOGIN_REQUIRED', displayMessage: 'Please log in again.'))]);

    try {
        app(PlaidClient::class)->getAccounts('access-1');
        $this->fail('Expected a PlaidException.');
    } catch (PlaidException $e) {
        expect($e->errorCode)->toBe('ITEM_LOGIN_REQUIRED')
            ->and($e->errorType)->toBe('ITEM_ERROR')
            ->and($e->requestId)->toBe('req-error')
            ->and($e->status)->toBe(400)
            ->and($e->requiresLogin())->toBeTrue()
            ->and($e->userMessage())->toBe('Please log in again.');
    }
});

it('refuses to call Plaid without credentials', function () {
    config()->set('services.plaid.client_id', null);

    expect(fn () => freshPlaidClient()->getAccounts('access-1'))
        ->toThrow(PlaidException::class, 'Plaid is not configured');

    Http::assertNothingSent();
});

it('talks to the production host when configured', function () {
    config()->set('services.plaid.env', 'production');
    Http::fake(['https://production.plaid.com/accounts/get' => Http::response(['accounts' => [], 'item' => []])]);

    freshPlaidClient()->getAccounts('access-1');

    Http::assertSentCount(1);
});

it('only allows sandbox helpers in the sandbox', function () {
    config()->set('services.plaid.env', 'production');

    expect(fn () => freshPlaidClient()->sandboxCreatePublicToken())->toThrow(InvalidArgumentException::class);
});
