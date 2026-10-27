<?php

namespace App\Models;

use App\Enums\CategorySource;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * `amount` keeps Plaid's sign convention: positive = money out of the account,
 * negative = money in. Use `signed_amount` for display (positive = money in).
 */
#[Fillable([
    'account_id',
    'plaid_transaction_id',
    'pending_plaid_transaction_id',
    'amount',
    'currency',
    'date',
    'authorized_date',
    'name',
    'merchant_name',
    'pending',
    'category_id',
    'category_source',
    'category_primary',
    'category_detailed',
    'payment_channel',
    'raw',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'date' => 'date',
            'authorized_date' => 'date',
            'pending' => 'boolean',
            'category_source' => CategorySource::class,
            'raw' => 'array',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isInflow(): bool
    {
        return (float) $this->amount < 0;
    }

    /** @return Attribute<float, never> */
    protected function signedAmount(): Attribute
    {
        return Attribute::get(fn (): float => -1 * (float) $this->amount);
    }

    /** @return Attribute<string, never> */
    protected function description(): Attribute
    {
        return Attribute::get(fn (): string => $this->merchant_name ?: $this->name);
    }

    /**
     * The date the purchase happened, which is what people recognise.
     *
     * @return Attribute<Carbon, never>
     */
    protected function displayDate(): Attribute
    {
        return Attribute::get(fn (): Carbon => $this->authorized_date ?? $this->date);
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->whereHas('account.plaidItem', fn (Builder $item) => $item->where('user_id', $user->getKey()));
    }

    #[Scope]
    protected function onlyPending(Builder $query): void
    {
        $query->where('pending', true);
    }
}
