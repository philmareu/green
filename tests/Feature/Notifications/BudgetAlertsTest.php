<?php

use App\Enums\CategorySource;
use App\Enums\OccurrenceResolution;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\Recurring;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BudgetAlert;
use App\Services\Notifications\NotifyBudgetAlerts;
use App\Services\Plaid\SyncPlaidItem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00');
    $this->account = Account::factory()->create();
    $this->groceries = Category::factory()->create(['name' => 'Groceries']);
});

/**
 * Groceries, $150 a week, from Mon Oct 19.
 */
function groceriesBudget(Account $account, Category $category, array $attributes = []): Recurring
{
    return Recurring::factory()->for($account)->flex($category)->create(['amount' => 150, 'starts_on' => '2026-10-19', ...$attributes]);
}

function spendOn(Account $account, Category $category, float $amount, string $date = '2026-10-20'): Transaction
{
    return Transaction::factory()->for($account)->create(['amount' => $amount, 'category_id' => $category->id, 'date' => $date, 'authorized_date' => $date]);
}

function checkBudgets(Account $account): void
{
    app(NotifyBudgetAlerts::class)->forAccounts([$account->id]);
}

it('warns once when a period reaches 80% of its budget, and alerts once more when it goes over', function () {
    Notification::fake();
    $groceries = groceriesBudget($this->account, $this->groceries);

    spendOn($this->account, $this->groceries, 119.99);
    checkBudgets($this->account);
    Notification::assertNothingSent();

    spendOn($this->account, $this->groceries, 0.01);
    checkBudgets($this->account);
    checkBudgets($this->account);
    Notification::assertSentToTimes($groceries->user, BudgetAlert::class, 1);

    spendOn($this->account, $this->groceries, 30.00);
    checkBudgets($this->account);
    Notification::assertSentToTimes($groceries->user, BudgetAlert::class, 1);

    spendOn($this->account, $this->groceries, 0.01);
    checkBudgets($this->account);
    checkBudgets($this->account);
    Notification::assertSentToTimes($groceries->user, BudgetAlert::class, 2);
});

it('sends only the over alert when spending jumps past the budget', function () {
    Notification::fake();
    $groceries = groceriesBudget($this->account, $this->groceries);
    spendOn($this->account, $this->groceries, 180.00);

    checkBudgets($this->account);

    Notification::assertSentToTimes($groceries->user, BudgetAlert::class, 1);
    expect($groceries->occurrences()->whereDate('due_on', '2026-10-19')->sole()->budget_alert)->toBe(100);
});

it('starts over in the next period', function () {
    Notification::fake();
    $groceries = groceriesBudget($this->account, $this->groceries);
    spendOn($this->account, $this->groceries, 125.00);
    checkBudgets($this->account);

    $this->travelTo('2026-10-27 12:00');
    $groceries->syncOccurrences();
    spendOn($this->account, $this->groceries, 125.00, '2026-10-27');
    checkBudgets($this->account);

    Notification::assertSentToTimes($groceries->user, BudgetAlert::class, 2);
});

it('stays quiet for a skipped period', function () {
    Notification::fake();
    $groceries = groceriesBudget($this->account, $this->groceries);
    $groceries->occurrences()->whereDate('due_on', '2026-10-19')->sole()->update(['resolution' => OccurrenceResolution::Skipped]);
    spendOn($this->account, $this->groceries, 180.00);

    checkBudgets($this->account);

    Notification::assertNothingSent();
});

it('stays quiet while its owner has budget alerts turned off', function () {
    Notification::fake();
    $user = User::factory()->create(['notification_preferences' => ['budget_alert' => []]]);
    $groceries = groceriesBudget($this->account, $this->groceries, ['user_id' => $user->id]);
    spendOn($this->account, $this->groceries, 180.00);

    checkBudgets($this->account);

    Notification::assertNothingSent();
    expect($groceries->occurrences()->whereDate('due_on', '2026-10-19')->sole()->budget_alert)->toBeNull();
});

it('puts the amounts in the bell and leaves them out of the email', function () {
    $groceries = groceriesBudget($this->account, $this->groceries);
    spendOn($this->account, $this->groceries, 127.50);

    checkBudgets($this->account);

    expect($groceries->user->notifications()->sole()->data)
        ->title->toBe('Groceries has used 85% of its budget')
        ->body->toBe('$127.50 of $150.00 spent, for the period through Sun, Oct 25.');

    $period = $groceries->occurrences()->whereDate('due_on', '2026-10-19')->sole();
    $mail = (string) (new BudgetAlert($groceries, $period, 160.00))->toMail($groceries->user)->render();

    expect($mail)->toContain('Groceries is over budget, for the period through Sun, Oct 25.')
        ->not->toContain('160')
        ->not->toContain('150');
});

it('checks budgets after a sync, once transactions are categorized', function () {
    Notification::fake();
    $set = CategorySet::factory()->create();
    $account = Account::factory()
        ->inBook(Book::factory()->for($set->user)->create(['category_set_id' => $set->id]))
        ->create(['plaid_account_id' => 'acc-checking']);
    $category = Category::factory()->for($set)->create(['name' => 'Groceries']);
    $groceries = groceriesBudget($account, $category);
    spendOn($account, $category, 180.00)->update(['category_source' => CategorySource::Manual]);
    Http::fake(fn (Request $request) => syncPage());

    app(SyncPlaidItem::class)->handle($account->plaidItem);

    Notification::assertSentTo($groceries->user, BudgetAlert::class);
});
