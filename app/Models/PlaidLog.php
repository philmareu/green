<?php

namespace App\Models;

use App\Enums\PlaidLogKind;
use App\Enums\PlaidLogResult;
use App\Enums\PlaidLogTrigger;
use Database\Factories\PlaidLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operation Green ran against Plaid (a sync, a Live balance refresh, a
 * link or a removal), for monitoring. `requests` lists each API call's
 * endpoint, HTTP status, Plaid `request_id` and error code, which match the
 * entries in Plaid's dashboard. Never tokens, request bodies or transaction
 * details.
 *
 * Entries belong to the user directly, so they outlive a removed connection
 * (`plaid_item_id` becomes null; `institution_name` stays), and are pruned
 * after RETENTION_DAYS.
 *
 * @property list<array{endpoint: string, status: ?int, request_id: ?string, error_code: ?string}>|null $requests
 */
#[Fillable([
    'user_id',
    'plaid_item_id',
    'institution_name',
    'kind',
    'triggered_by',
    'result',
    'started_at',
    'duration_ms',
    'pages',
    'added',
    'modified',
    'removed',
    'error_code',
    'error_message',
    'requests',
])]
#[WithoutTimestamps]
class PlaidLog extends Model
{
    /** @use HasFactory<PlaidLogFactory> */
    use HasFactory;

    use MassPrunable;

    public const int RETENTION_DAYS = 14;

    protected function casts(): array
    {
        return [
            'kind' => PlaidLogKind::class,
            'triggered_by' => PlaidLogTrigger::class,
            'result' => PlaidLogResult::class,
            'started_at' => 'datetime',
            'duration_ms' => 'integer',
            'pages' => 'integer',
            'added' => 'integer',
            'modified' => 'integer',
            'removed' => 'integer',
            'requests' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PlaidItem, $this> */
    public function plaidItem(): BelongsTo
    {
        return $this->belongsTo(PlaidItem::class);
    }

    /**
     * Attach the entry to a connection, keeping its bank's name for after
     * the connection is removed.
     */
    public function forItem(PlaidItem $item): static
    {
        return $this->forceFill([
            'user_id' => $item->user_id,
            'plaid_item_id' => $item->getKey(),
            'institution_name' => $item->institution_name,
        ]);
    }

    /**
     * Pages a sync fetched, counting every /transactions/sync response,
     * including ones from a pass Plaid made Green restart.
     */
    public function countPages(): int
    {
        return collect($this->requests ?? [])
            ->filter(fn (array $request): bool => $request['endpoint'] === '/transactions/sync'
                && $request['status'] !== null
                && $request['status'] < 300)
            ->count();
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('started_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }
}
