<?php

namespace App\Models;

use App\Enums\BookKind;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

#[Fillable(['user_id', 'name', 'kind'])]
class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => BookKind::class,
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

    /**
     * Cash you can spend across this Book's visible bank accounts, using each
     * account's available balance (which already reflects pending activity).
     */
    public function cashAvailable(): float
    {
        return round($this->visibleAccounts()
            ->reject(fn (Account $account) => $account->isCredit())
            ->sum(fn (Account $account) => $account->spendableBalance() ?? 0), 2);
    }

    /** Total owed on this Book's visible credit cards and loans. */
    public function creditOwed(): float
    {
        return round($this->visibleAccounts()
            ->filter(fn (Account $account) => $account->isCredit())
            ->sum(fn (Account $account) => (float) $account->current_balance), 2);
    }

    public function currency(): string
    {
        return $this->visibleAccounts()->first()?->currency ?? 'USD';
    }

    /** @return Collection<int, Account> */
    protected function visibleAccounts(): Collection
    {
        return $this->accounts->reject(fn (Account $account) => $account->is_hidden);
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }
}
