<?php

namespace App\Models;

use App\Enums\BookKind;
use App\Enums\CategoryType;
use Database\Factories\CategorySetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named list of categories, such as "Business" or "Personal". Each Book uses
 * one set; several Books can share a set.
 */
#[Fillable(['user_id', 'name', 'kind'])]
class CategorySet extends Model
{
    /** @use HasFactory<CategorySetFactory> */
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

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    /**
     * Options for picking one of this set's categories, grouped by type, each
     * parent followed by its subcategories.
     *
     * @return array<string, array<int, string>>
     */
    public function categoryOptions(): array
    {
        return $this->categories()->with('parent')->get()
            ->sortBy(fn (Category $category): string => implode("\0", [
                array_search($category->type, CategoryType::cases(), strict: true),
                mb_strtolower($category->parent?->name ?? $category->name),
                $category->parent_id === null ? 0 : 1,
                mb_strtolower($category->name),
            ]))
            ->groupBy(fn (Category $category): string => $category->type->getLabel())
            ->map(fn ($categories): array => $categories->mapWithKeys(fn (Category $category): array => [$category->getKey() => $category->full_name])->all())
            ->all();
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }
}
