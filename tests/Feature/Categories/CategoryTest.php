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

it('tidies payees, dropping blanks and repeats that differ only in case', function () {
    $category = Category::factory()->create(['payees' => ['  Slack ', 'slack', '', 'GitHub']]);

    expect($category->fresh()->payees)->toBe(['Slack', 'GitHub']);

    $category->update(['payees' => [' ']]);

    expect($category->fresh()->payees)->toBeNull();
});

it('moves a payee from another category in the same set, but not from another set', function () {
    $software = Category::factory()->create(['payees' => ['Slack', 'GitHub']]);
    $elsewhere = Category::factory()->create(['payees' => ['slack']]);

    Category::factory()->for($software->categorySet)->create(['payees' => ['SLACK']]);

    expect($software->fresh()->payees)->toBe(['GitHub'])
        ->and($elsewhere->fresh()->payees)->toBe(['slack']);
});
