<?php

namespace App\Models;

use App\Enums\PlaidItemStatus;
use Database\Factories\PlaidItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A Plaid "Item" is one login at one institution. Its accounts may belong to
 * different Books (e.g. business and personal checking under the same login).
 */
#[Fillable([
    'user_id',
    'plaid_item_id',
    'access_token',
    'institution_id',
    'institution_name',
    'sync_cursor',
    'transactions_update_status',
    'last_synced_at',
    'status',
    'error_code',
    'error_message',
])]
#[Hidden(['access_token', 'sync_cursor'])]
class PlaidItem extends Model
{
    /** @use HasFactory<PlaidItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'status' => PlaidItemStatus::class,
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasManyThrough<Transaction, Account, $this> */
    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(Transaction::class, Account::class);
    }

    public function needsReconnect(): bool
    {
        return $this->status === PlaidItemStatus::LoginRequired;
    }

    public function isHistoryLoading(): bool
    {
        return $this->transactions_update_status !== null
            && $this->transactions_update_status !== 'HISTORICAL_UPDATE_COMPLETE';
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }
}
