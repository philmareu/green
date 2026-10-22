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
 */
#[Fillable(['category_set_id', 'parent_id', 'name', 'type'])]
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
        });

        static::updated(function (Category $category): void {
            if ($category->wasChanged('type')) {
                $category->children()->update(['type' => $category->type]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
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
