<?php

use App\Enums\PlaidItemStatus;
use App\Filament\Pages\LinkBank;
use App\Filament\Resources\Books\BookResource;
use App\Filament\Resources\PlaidItems\PlaidItemResource;
use App\Models\Account;
use App\Models\Book;
use App\Models\PlaidItem;
use App\Models\Transaction;
use App\Services\Plaid\PlaidClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->book = Book::factory()->create(['name' => 'Acme LLC']);
    $this->actingAs($this->book->user);
});

function fakeLinkFlow(): void
{
    Http::fake([
        PLAID.'/link/token/create' => Http::response(['link_token' => 'link-sandbox-123', 'expiration' => '', 'request_id' => 'r']),
        PLAID.'/item/public_token/exchange' => Http::response(['access_token' => 'access-sandbox-new', 'item_id' => 'item-new', 'request_id' => 'r']),
        PLAID.'/accounts/get' => Http::response([
            'accounts' => [plaidAccount(), plaidAccount(['account_id' => 'acc-card', 'name' => 'Plaid Credit Card', 'type' => 'credit', 'subtype' => 'credit card'])],
            'item' => ['item_id' => 'item-new', 'institution_id' => 'ins_109508'],
        ]),
        PLAID.'/transactions/sync' => Http::response(syncPage(
            added: [plaidTransaction(), plaidTransaction(['transaction_id' => 'tx-pending', 'pending' => true])],
            accounts: [plaidAccount()],
        )),
    ]);
}

it('creates a link token for the Book', function () {
    fakeLinkFlow();

    Livewire::withQueryParams(['book' => $this->book->id])
        ->test(LinkBank::class)
        ->assertOk()
        ->assertSet('linkToken', 'link-sandbox-123')
        ->assertSet('bookId', $this->book->id)
        ->assertSee('Link a bank to Acme LLC')
        ->assertSee('Continue to Plaid');

    expect(session('plaid.link.token'))->toBe('link-sandbox-123');
});

it('imports the item, its accounts and transactions after Link succeeds', function () {
    fakeLinkFlow();

    Livewire::withQueryParams(['book' => $this->book->id])
        ->test(LinkBank::class)
        ->call('exchange', 'public-sandbox-xyz', 'ins_109508', 'First Platypus Bank')
        ->assertRedirect(BookResource::getUrl('view', ['record' => $this->book]));

    $item = PlaidItem::sole();

    expect($item->user_id)->toBe($this->book->user_id)
        ->and($item->plaid_item_id)->toBe('item-new')
        ->and($item->access_token)->toBe('access-sandbox-new')
        ->and($item->institution_name)->toBe('First Platypus Bank')
        ->and($item->sync_cursor)->toBe('cursor-final')
        ->and(Account::where('book_id', $this->book->id)->count())->toBe(2)
        ->and(Transaction::count())->toBe(2);

    Http::assertSent(fn (Request $request) => $request->url() === PLAID.'/item/public_token/exchange'
        && $request['public_token'] === 'public-sandbox-xyz');
});

it('stores the access token encrypted', function () {
    fakeLinkFlow();

    Livewire::withQueryParams(['book' => $this->book->id])
        ->test(LinkBank::class)
        ->call('exchange', 'public-sandbox-xyz', 'ins_109508', 'First Platypus Bank');

    $raw = DB::table('plaid_items')->value('access_token');

    expect($raw)->not->toBe('access-sandbox-new')
        ->and(decrypt($raw, unserialize: false))->toBe('access-sandbox-new');
});

it('reconnects an item in update mode without exchanging a token', function () {
    fakeLinkFlow();
    $item = PlaidItem::factory()->for($this->book->user)->loginRequired()->create();

    Livewire::withQueryParams(['item' => $item->id])
        ->test(LinkBank::class)
        ->assertSet('linkToken', 'link-sandbox-123')
        ->assertSee('Reconnect')
        ->call('exchange', 'public-sandbox-ignored')
        ->assertRedirect(PlaidItemResource::getUrl());

    expect($item->fresh()->status)->toBe(PlaidItemStatus::Active)
        ->and($item->fresh()->error_code)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === PLAID.'/link/token/create'
        && $request['access_token'] === $item->access_token);
    Http::assertNotSent(fn (Request $request) => $request->url() === PLAID.'/item/public_token/exchange');
});

it('does not let you link into someone else’s Book', function () {
    fakeLinkFlow();
    $stranger = Book::factory()->create();

    Livewire::withQueryParams(['book' => $stranger->id])
        ->test(LinkBank::class)
        ->assertNotFound();
});

it('does not take over a bank connection that belongs to someone else', function () {
    fakeLinkFlow();
    $theirs = PlaidItem::factory()->create(['plaid_item_id' => 'item-new']);

    expect(fn () => Livewire::withQueryParams(['book' => $this->book->id])
        ->test(LinkBank::class)
        ->call('exchange', 'public-sandbox-xyz', 'ins_109508', 'First Platypus Bank'))
        ->toThrow(InvalidArgumentException::class, 'That bank connection belongs to another user.');

    expect($theirs->fresh())
        ->user_id->toBe($theirs->user_id)
        ->access_token->toBe($theirs->access_token)
        ->and(PlaidItem::query()->ownedBy($this->book->user)->count())->toBe(0);
});

it('explains when Plaid is not configured', function () {
    config()->set('services.plaid.secret', null);
    app()->forgetInstance(PlaidClient::class);

    Livewire::withQueryParams(['book' => $this->book->id])
        ->test(LinkBank::class)
        ->assertSet('linkToken', null)
        ->assertSee('Plaid is not configured');
});

it('shows Link errors', function () {
    fakeLinkFlow();

    Livewire::withQueryParams(['book' => $this->book->id])
        ->test(LinkBank::class)
        ->call('linkFailed', 'INSTITUTION_DOWN', 'This bank is temporarily unavailable.')
        ->assertSet('errorMessage', 'This bank is temporarily unavailable.')
        ->assertSee('This bank is temporarily unavailable.');
});

it('resumes the Link session when an OAuth bank redirects back', function () {
    $this->withSession(['plaid.link.return_url' => LinkBank::getUrl(['book' => $this->book->id])])
        ->get('/plaid/oauth?oauth_state_id=abc-123')
        ->assertRedirect(LinkBank::getUrl(['book' => $this->book->id]).'&oauth=1');

    expect(session('plaid.link.received_redirect_uri'))->toEndWith('/plaid/oauth?oauth_state_id=abc-123');

    session()->put('plaid.link.token', 'link-from-before');

    Livewire::withQueryParams(['book' => $this->book->id, 'oauth' => 1])
        ->test(LinkBank::class)
        ->assertSet('linkToken', 'link-from-before')
        ->assertSet('receivedRedirectUri', url('/plaid/oauth?oauth_state_id=abc-123'));

    Http::assertNothingSent();
});
