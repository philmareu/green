<?php

namespace App\Models;

use App\Enums\CategoryType;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category in a set. Categories nest one level deep ("Travel and meals ›
 * Meals"), and a subcategory always has its parent's type so totals roll up.
 * `plaid_categories` lists the Plaid categories (primary or detailed codes)
 * whose transactions are suggested for this category. `payees` lists text a
 * transaction's description may contain to get this category, which beats
 * Plaid's category. A payee belongs to one category in a set: adding it to
 * one removes it from the others.
 */
#[Fillable(['category_set_id', 'parent_id', 'name', 'type', 'plaid_categories', 'payees'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            if ($category->parent_id !== null) {
                $category->type = static::query()->whereKey($category->parent_id)->value('type');
            }

            if ($category->isDirty('payees')) {
                $category->payees = collect($category->payees)
                    ->map(fn (?string $payee): string => trim((string) $payee))
                    ->filter()
                    ->unique(fn (string $payee): string => mb_strtolower($payee))
                    ->values()
                    ->all() ?: null;
            }
        });

        static::saved(function (Category $category): void {
            if ($category->payees === null || ! ($category->wasRecentlyCreated || $category->wasChanged('payees'))) {
                return;
            }

            $taken = collect($category->payees)->map(fn (string $payee): string => mb_strtolower($payee));

            static::query()->where('category_set_id', $category->category_set_id)->whereKeyNot($category->getKey())->whereNotNull('payees')->get()
                ->each(function (Category $other) use ($taken): void {
                    $kept = collect($other->payees)->reject(fn (string $payee): bool => $taken->contains(mb_strtolower($payee)));

                    if ($kept->count() !== count($other->payees)) {
                        $other->update(['payees' => $kept->values()->all()]);
                    }
                });
        });

        static::updated(function (Category $category): void {
            if ($category->wasChanged('type')) {
                $category->children()->update(['type' => $category->type]);
            }
        });

        // Deleting a category keeps its subcategories, at the top level.
        static::deleting(function (Category $category): void {
            $category->children()->update(['parent_id' => null]);
        });
    }

    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
            'plaid_categories' => 'array',
            'payees' => 'array',
        ];
    }

    /** @return BelongsTo<CategorySet, $this> */
    public function categorySet(): BelongsTo
    {
        return $this->belongsTo(CategorySet::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /** @return Attribute<string, never> */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => $this->parent
            ? "{$this->parent->name} › {$this->name}"
            : $this->name);
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->whereHas('categorySet', fn (Builder $set) => $set->where('user_id', $user->getKey()));
    }

    #[Scope]
    protected function topLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }
}
