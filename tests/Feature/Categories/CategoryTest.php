<?php

use App\Enums\CategoryType;
use App\Models\Category;

it('keeps subcategories on their parent’s type', function () {
    $parent = Category::factory()->income()->create();
    $child = Category::factory()->childOf($parent)->create(['type' => CategoryType::Expense]);

    expect($child->type)->toBe(CategoryType::Income);

    $parent->update(['type' => CategoryType::Transfer]);

    expect($child->fresh()->type)->toBe(CategoryType::Transfer);
});
