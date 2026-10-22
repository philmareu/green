<?php

use App\Enums\CategoryType;
use App\Models\Book;
use App\Models\CategorySet;
use App\Models\User;
use App\Services\Categories\StarterCategories;

it('adds the Schedule C starter categories to a business set', function () {
    $set = CategorySet::factory()->business()->create();

    app(StarterCategories::class)->addTo($set);

    $categories = $set->categories()->with('parent')->get()->keyBy('full_name');

    expect($categories['Sales and services']->type)->toBe(CategoryType::Income)
        ->and($categories['Contract labor']->type)->toBe(CategoryType::Expense)
        ->and($categories['Travel and meals › Meals']->type)->toBe(CategoryType::Expense)
        ->and($categories['Owner’s draw']->type)->toBe(CategoryType::Transfer);
});

it('gives a user one starter set per Book kind, used by Books without one', function () {
    $user = User::factory()->create();
    $business = Book::factory()->for($user)->business()->create();
    $personal = Book::factory()->for($user)->personal()->create();
    $alreadySet = Book::factory()->for($user)->business()->create([
        'category_set_id' => CategorySet::factory()->for($user)->business(),
    ]);

    $sets = app(StarterCategories::class)->createSetsFor($user)->keyBy(fn (CategorySet $set) => $set->kind->value);

    expect($sets->map->name->all())->toBe(['business' => 'Business', 'personal' => 'Personal'])
        ->and($sets['personal']->categories()->where('name', 'Groceries')->exists())->toBeTrue()
        ->and($business->fresh()->category_set_id)->toBe($sets['business']->id)
        ->and($personal->fresh()->category_set_id)->toBe($sets['personal']->id)
        ->and($alreadySet->fresh()->category_set_id)->toBe($alreadySet->category_set_id);
});
