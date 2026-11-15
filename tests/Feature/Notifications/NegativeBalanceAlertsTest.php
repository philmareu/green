<?php

use App\Models\Account;
use App\Models\PlaidItem;
use App\Models\User;
use App\Notifications\AccountWentNegative;
use App\Services\Notifications\NotifyNegativeBalances;
use App\Services\Plaid\SyncPlaidItem;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function negativeAccount(array $attributes = []): Account
{
    return Account::factory()->create(['available_balance' => -12.50, ...$attributes]);
}

function checkBalances(Account $account): void
{
    app(NotifyNegativeBalances::class)->forItem($account->plaidItem);
}

it('alerts the owner once when an account goes negative', function () {
    Notification::fake();
    $account = negativeAccount();

    checkBalances($account);
    checkBalances($account->refresh());

    Notification::assertSentToTimes($account->plaidItem->user, AccountWentNegative::class, 1);
});

it('alerts again after the balance recovers and goes negative a second time', function () {
    Notification::fake();
    $account = negativeAccount();

    checkBalances($account);
    $account->update(['available_balance' => 40]);
    checkBalances($account);
    expect($account->refresh()->negative_notified_at)->toBeNull();

    $account->update(['available_balance' => -5]);
    checkBalances($account);

    Notification::assertSentToTimes($account->plaidItem->user, AccountWentNegative::class, 2);
});

it('counts pending transactions by using the available balance over the current one', function () {
    Notification::fake();
    $account = negativeAccount(['current_balance' => 100, 'available_balance' => -12.50]);

    checkBalances($account);

    Notification::assertSentTo($account->plaidItem->user, AccountWentNegative::class);
});

it('falls back to the current balance when the bank reports no available balance', function () {
    Notification::fake();
    $account = negativeAccount(['current_balance' => -3, 'available_balance' => null]);

    checkBalances($account);

    Notification::assertSentTo($account->plaidItem->user, AccountWentNegative::class);
});

it('stays quiet for accounts that are not negative cash accounts', function (array $attributes) {
    Notification::fake();
    $account = negativeAccount($attributes);

    checkBalances($account);

    Notification::assertNothingSent();
})->with([
    'positive' => [['available_balance' => 10]],
    'exactly zero' => [['available_balance' => 0]],
    'credit card' => [['type' => 'credit']],
    'loan' => [['type' => 'loan']],
    'hidden' => [['is_hidden' => true]],
    'no balance reported' => [['available_balance' => null, 'current_balance' => null]],
]);

it('stays quiet when the owner turned the alert off, and alerts once they turn it on', function () {
    Notification::fake();
    $item = PlaidItem::factory()
        ->for(User::factory()->create(['notification_preferences' => ['negative_balance' => []]]))
        ->create();
    $account = negativeAccount(['plaid_item_id' => $item->id]);

    checkBalances($account);
    Notification::assertNothingSent();

    $item->user->forceFill(['notification_preferences' => ['negative_balance' => ['database']]])->save();
    checkBalances($account->refresh());

    Notification::assertSentTo($item->user, AccountWentNegative::class);
});

it('sends to the channels the owner chose', function (?array $preferences, array $channels) {
    $user = User::factory()->create(['notification_preferences' => $preferences]);

    expect((new AccountWentNegative(negativeAccount(), -12.50))->via($user))->toBe($channels);
})->with([
    'default' => [null, ['database']],
    'both' => [['negative_balance' => ['database', 'mail']], ['database', 'mail']],
    'email only' => [['negative_balance' => ['mail']], ['mail']],
]);

it('puts the balance in the bell but not in the email', function () {
    $account = negativeAccount(['name' => 'Checking', 'mask' => '1234']);
    $user = $account->plaidItem->user;

    checkBalances($account);

    expect($user->notifications()->sole()->data)
        ->title->toBe('Checking ••1234 is negative')
        ->body->toContain('-$12.50');

    $mail = (new AccountWentNegative($account, -12.50))->toMail($user);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and((string) $mail->render())->toContain('Checking ••1234')->not->toContain('12.50');
});

it('alerts after a sync that takes the account negative', function () {
    Notification::fake();
    $account = Account::factory()->create(['plaid_account_id' => 'acc-checking', 'available_balance' => 25]);
    Http::fake(fn (Request $request) => syncPage(accounts: [plaidAccount([
        'account_id' => 'acc-checking',
        'balances' => ['available' => -8, 'current' => 25],
    ])]));

    app(SyncPlaidItem::class)->handle($account->plaidItem);

    Notification::assertSentTo($account->plaidItem->user, AccountWentNegative::class);
});

it('reports a failed alert without failing the sync', function () {
    Exceptions::fake();
    $failure = new RuntimeException('Mail is down');
    $this->mock(NotifyNegativeBalances::class)->shouldReceive('forItem')->andThrow($failure);
    $account = Account::factory()->create(['plaid_account_id' => 'acc-checking']);
    Http::fake(fn (Request $request) => syncPage());

    $result = app(SyncPlaidItem::class)->handle($account->plaidItem);

    expect($result->successful)->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});
