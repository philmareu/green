<?php

use App\Jobs\SyncPlaidItemJob;
use App\Models\Book;
use App\Models\PlaidItem;
use App\Models\User;
use App\Services\Plaid\PlaidClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('queues a sync for every connection that can sync', function () {
    Queue::fake();
    $active = PlaidItem::factory()->create();
    $errored = PlaidItem::factory()->create(['status' => 'error']);
    PlaidItem::factory()->loginRequired()->create();

    $this->artisan('plaid:sync')->assertSuccessful();

    Queue::assertPushed(SyncPlaidItemJob::class, 2);
    Queue::assertPushed(SyncPlaidItemJob::class, fn ($job) => $job->item->is($active));
    Queue::assertPushed(SyncPlaidItemJob::class, fn ($job) => $job->item->is($errored));
});

it('can sync a single connection', function () {
    Queue::fake();
    $item = PlaidItem::factory()->create();
    PlaidItem::factory()->create();

    $this->artisan('plaid:sync', ['--item' => $item->id])->assertSuccessful();

    Queue::assertPushed(SyncPlaidItemJob::class, 1);
});

it('schedules the hourly sync', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('plaid:sync')->assertSuccessful();
});

it('links a sandbox bank to a Book', function () {
    $book = Book::factory()->create(['name' => 'Acme LLC']);
    Http::fake([
        PLAID.'/sandbox/public_token/create' => Http::response(['public_token' => 'public-sandbox-1', 'request_id' => 'r']),
        PLAID.'/item/public_token/exchange' => Http::response(['access_token' => 'access-sandbox-1', 'item_id' => 'item-1', 'request_id' => 'r']),
        PLAID.'/item/get' => Http::response(['item' => ['institution_id' => 'ins_109508']]),
        PLAID.'/institutions/get_by_id' => Http::response(['institution' => ['institution_id' => 'ins_109508', 'name' => 'First Platypus Bank']]),
        PLAID.'/accounts/get' => Http::response(['accounts' => [plaidAccount()], 'item' => []]),
        PLAID.'/transactions/sync' => Http::response(syncPage(added: [plaidTransaction()])),
    ]);

    $this->artisan('plaid:sandbox-link', ['book' => $book->id])
        ->expectsOutputToContain('Linked First Platypus Bank with 1 account(s) to "Acme LLC"')
        ->assertSuccessful();

    expect($book->accounts()->count())->toBe(1)
        ->and($book->transactions()->count())->toBe(1);
});

it('refuses to sandbox-link outside the sandbox', function () {
    config()->set('services.plaid.env', 'production');
    app()->forgetInstance(PlaidClient::class);

    $this->artisan('plaid:sandbox-link', ['book' => Book::factory()->create()->id])->assertFailed();
});

it('installs and creates the first login', function () {
    $this->artisan('green:install', ['--name' => 'Phil', '--email' => 'phil@example.com', '--password' => 'secret-password'])
        ->assertSuccessful();

    expect(User::where('email', 'phil@example.com')->exists())->toBeTrue();
});
