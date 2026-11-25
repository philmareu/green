<?php

namespace Database\Factories;

use App\Enums\PlaidLogKind;
use App\Enums\PlaidLogResult;
use App\Enums\PlaidLogTrigger;
use App\Models\PlaidItem;
use App\Models\PlaidLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A finished, successful scheduled sync by default.
 *
 * @extends Factory<PlaidLog>
 */
class PlaidLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plaid_item_id' => PlaidItem::factory(),
            // The connection's owner and bank, as PlaidLog::forItem() sets them.
            'user_id' => fn (array $attributes) => PlaidItem::query()->find($attributes['plaid_item_id'])?->user_id ?? User::factory(),
            'institution_name' => fn (array $attributes) => PlaidItem::query()->find($attributes['plaid_item_id'])?->institution_name,
            'kind' => PlaidLogKind::Sync,
            'triggered_by' => PlaidLogTrigger::Scheduled,
            'result' => PlaidLogResult::Succeeded,
            'started_at' => now(),
            'duration_ms' => fake()->numberBetween(300, 2500),
            'pages' => 1,
            'added' => fake()->numberBetween(0, 3),
            'modified' => fake()->numberBetween(0, 2),
            'removed' => 0,
            'requests' => [$this->request('/transactions/sync')],
        ];
    }

    /**
     * A sync Plaid refused on its first page.
     */
    public function failed(string $errorCode = 'ITEM_LOGIN_REQUIRED'): static
    {
        return $this->state(fn () => [
            'result' => PlaidLogResult::Failed,
            'pages' => 0,
            'added' => null,
            'modified' => null,
            'removed' => null,
            'error_code' => $errorCode,
            'error_message' => "Plaid error {$errorCode}",
            'requests' => [$this->request('/transactions/sync', status: 400, errorCode: $errorCode)],
        ]);
    }

    /**
     * Started by someone pressing "Sync now" rather than by the scheduler.
     */
    public function manual(): static
    {
        return $this->state(fn () => ['triggered_by' => PlaidLogTrigger::Manual]);
    }

    /**
     * A bank being linked. The first sync that follows is an entry of its own.
     */
    public function link(): static
    {
        return $this->state(fn () => [
            'kind' => PlaidLogKind::Link,
            'triggered_by' => PlaidLogTrigger::Manual,
            'pages' => null,
            'added' => null,
            'modified' => null,
            'removed' => null,
            'requests' => [
                $this->request('/item/public_token/exchange'),
                $this->request('/accounts/get'),
            ],
        ]);
    }

    /**
     * The sync that follows a link and loads the bank's history, over
     * several pages.
     */
    public function firstSync(int $added, int $pages = 2): static
    {
        return $this->state(fn () => [
            'triggered_by' => PlaidLogTrigger::Link,
            'pages' => $pages,
            'added' => $added,
            'modified' => 0,
            'requests' => array_map(fn () => $this->request('/transactions/sync'), range(1, $pages)),
        ]);
    }

    /**
     * A bank being removed. The entry outlives the connection and keeps only
     * the bank's name, so give it its user with `for()`.
     */
    public function removal(string $institutionName): static
    {
        return $this->state(fn () => [
            'plaid_item_id' => null,
            'institution_name' => $institutionName,
            'kind' => PlaidLogKind::Remove,
            'triggered_by' => PlaidLogTrigger::Manual,
            'pages' => null,
            'added' => null,
            'modified' => null,
            'removed' => null,
            'requests' => [$this->request('/item/remove')],
        ]);
    }

    public function liveBalance(): static
    {
        return $this->state(fn () => [
            'kind' => PlaidLogKind::LiveBalance,
            'triggered_by' => PlaidLogTrigger::Manual,
            'pages' => null,
            'added' => null,
            'modified' => null,
            'removed' => null,
            'requests' => [$this->request('/accounts/balance/get')],
        ]);
    }

    /**
     * @return array{endpoint: string, status: int, request_id: string, error_code: ?string}
     */
    protected function request(string $endpoint, int $status = 200, ?string $errorCode = null): array
    {
        return ['endpoint' => $endpoint, 'status' => $status, 'request_id' => Str::random(15), 'error_code' => $errorCode];
    }
}
