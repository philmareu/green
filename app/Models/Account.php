<?php

namespace App\Models;

use App\Services\Categories\CategorizeTransactions;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'plaid_item_id',
    'book_id',
    'plaid_account_id',
    'name',
    'official_name',
    'mask',
    'type',
    'subtype',
    'currency',
    'current_balance',
    'available_balance',
    'credit_limit',
    'balances_updated_at',
    'is_hidden',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // A different Book can mean a different category set.
        static::updated(function (Account $account): void {
            if ($account->wasChanged('book_id')) {
                app(CategorizeTransactions::class)->handle($account->transactions()->getQuery());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:4',
            'available_balance' => 'decimal:4',
            'credit_limit' => 'decimal:4',
            'balances_updated_at' => 'datetime',
            'negative_notified_at' => 'datetime',
            'is_hidden' => 'boolean',
        ];
    }

    /** @return BelongsTo<PlaidItem, $this> */
    public function plaidItem(): BelongsTo
    {
        return $this->belongsTo(PlaidItem::class);
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Recurring, $this> */
    public function recurrings(): HasMany
    {
        return $this->hasMany(Recurring::class);
    }

    /**
     * Credit and loan balances are amounts owed, and "available" means remaining
     * credit rather than cash, so they are reported separately from cash.
     */
    public function isCredit(): bool
    {
        return in_array($this->type, ['credit', 'loan'], true);
    }

    /**
     * What you can spend right now. Plaid's available balance already accounts
     * for pending activity; fall back to current when the bank doesn't report it.
     */
    public function spendableBalance(): ?float
    {
        $balance = $this->available_balance ?? $this->current_balance;

        return $balance === null ? null : (float) $balance;
    }

    /** @return Attribute<string, never> */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->mask
            ? "{$this->name} ••{$this->mask}"
            : $this->name);
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->whereHas('plaidItem', fn (Builder $item) => $item->where('user_id', $user->getKey()));
    }

    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    #[Scope]
    protected function cash(Builder $query): void
    {
        $query->whereNotIn('type', ['credit', 'loan']);
    }

    #[Scope]
    protected function credit(Builder $query): void
    {
        $query->whereIn('type', ['credit', 'loan']);
    }
}
