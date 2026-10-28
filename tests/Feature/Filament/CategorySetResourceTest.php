<?php

use App\Enums\BookKind;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Filament\Resources\CategorySets\Pages\CreateCategorySet;
use App\Filament\Resources\CategorySets\Pages\EditCategorySet;
use App\Filament\Resources\CategorySets\Pages\ListCategorySets;
use App\Filament\Resources\CategorySets\Pages\ViewCategorySet;
use App\Filament\Resources\CategorySets\RelationManagers\CategoriesRelationManager;
use App\Models\Account;
use App\Models\Book;
use App\Models\Category;
use App\Models\CategorySet;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function manageCategories(CategorySet $set): mixed
{
    return Livewire::test(CategoriesRelationManager::class, ['ownerRecord' => $set, 'pageClass' => ViewCategorySet::class]);
}

it('lists only your category sets', function () {
    $mine = CategorySet::factory()->for($this->user)->create();
    $theirs = CategorySet::factory()->create();

    Livewire::test(ListCategorySets::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('creates a set with suggested categories', function () {
    Livewire::test(CreateCategorySet::class)
        ->fillForm(['name' => 'Acme', 'kind' => BookKind::Business->value, 'with_starter_categories' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $set = CategorySet::sole();

    expect($set->user_id)->toBe($this->user->id)
        ->and($set->categories()->where('name', 'Contract labor')->exists())->toBeTrue();
});

it('creates an empty set when suggestions are turned off', function () {
    Livewire::test(CreateCategorySet::class)
        ->fillForm(['name' => 'Rental property', 'kind' => BookKind::Business->value, 'with_starter_categories' => false])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CategorySet::sole()->categories()->count())->toBe(0);
});

it('adds starter sets from the empty list', function () {
    Livewire::test(ListCategorySets::class)
        ->callAction(TestAction::make('addStarterSets')->table())
        ->assertNotified();

    expect($this->user->categorySets()->pluck('name')->sort()->values()->all())->toBe(['Business', 'Personal']);
});

it('hides other users’ category sets', function () {
    $theirs = CategorySet::factory()->create();

    Livewire::test(ViewCategorySet::class, ['record' => $theirs->getRouteKey()])->assertNotFound();
    Livewire::test(EditCategorySet::class, ['record' => $theirs->getRouteKey()])->assertNotFound();
});

it('deletes a set with its categories and leaves its Books without one', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $parent = Category::factory()->for($set)->create();
    Category::factory()->childOf($parent)->create();
    $book = Book::factory()->for($this->user)->create(['category_set_id' => $set->id]);

    Livewire::test(EditCategorySet::class, ['record' => $set->getRouteKey()])
        ->callAction('delete');

    expect(CategorySet::query()->whereKey($set->id)->exists())->toBeFalse()
        ->and(Category::query()->count())->toBe(0)
        ->and($book->fresh()->category_set_id)->toBeNull();
});

it('adds a subcategory with its parent’s type', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $parent = Category::factory()->for($set)->income()->create(['name' => 'Sales']);

    manageCategories($set)
        ->callAction(TestAction::make('create')->table(), data: ['name' => 'Consulting', 'parent_id' => $parent->id])
        ->assertHasNoFormErrors();

    expect($set->categories()->where('name', 'Consulting')->sole())
        ->parent_id->toBe($parent->id)
        ->type->toBe(CategoryType::Income);
});

it('only offers top-level categories in the same set as parents', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $parent = Category::factory()->for($set)->create();
    $subcategory = Category::factory()->childOf($parent)->create();
    $elsewhere = Category::factory()->for(CategorySet::factory()->for($this->user))->create();

    foreach ([$subcategory, $elsewhere] as $invalidParent) {
        manageCategories($set)
            ->callAction(TestAction::make('create')->table(), data: ['name' => 'Fuel', 'parent_id' => $invalidParent->id])
            ->assertHasFormErrors(['parent_id']);
    }

    expect($set->categories()->where('name', 'Fuel')->exists())->toBeFalse();
});

it('keeps a category with subcategories at the top level', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $vehicle = Category::factory()->for($set)->create(['name' => 'Vehicle']);
    Category::factory()->childOf($vehicle)->create(['name' => 'Fuel']);
    $travel = Category::factory()->for($set)->create(['name' => 'Travel']);

    manageCategories($set)
        ->callAction(TestAction::make('edit')->table($vehicle), data: ['parent_id' => $travel->id])
        ->assertHasNoFormErrors();

    expect($vehicle->fresh()->parent_id)->toBeNull();
});

it('rejects a duplicate name under the same parent', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $food = Category::factory()->for($set)->create(['name' => 'Food']);
    $travel = Category::factory()->for($set)->create(['name' => 'Travel']);
    Category::factory()->childOf($food)->create(['name' => 'Meals']);

    manageCategories($set)
        ->callAction(TestAction::make('create')->table(), data: ['name' => 'Meals', 'parent_id' => $food->id])
        ->assertHasFormErrors(['name' => 'unique']);

    manageCategories($set)
        ->callAction(TestAction::make('create')->table(), data: ['name' => 'Meals', 'parent_id' => $travel->id])
        ->assertHasNoFormErrors();
});

it('moves subcategories to the top level when their parent is deleted', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $parent = Category::factory()->for($set)->create();
    $child = Category::factory()->childOf($parent)->create();

    manageCategories($set)->callAction(TestAction::make('delete')->table($parent));

    expect($parent->fresh())->toBeNull()
        ->and($child->fresh()->parent_id)->toBeNull();
});

it('categorizes a payee’s transactions when you add it to a category', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $software = Category::factory()->for($set)->create(['name' => 'Software']);
    $book = Book::factory()->for($this->user)->create(['category_set_id' => $set->id]);
    $slack = Transaction::factory()->for(Account::factory()->inBook($book))->create(['name' => 'SLACK T0123', 'merchant_name' => null]);

    manageCategories($set)
        ->callAction(TestAction::make('edit')->table($software), data: ['payees' => ['slack']])
        ->assertHasNoFormErrors();

    expect($slack->fresh())->category_id->toBe($software->id)->category_source->toBe(CategorySource::Payee);
});

it('refreshes suggestions when a category’s Plaid categories change', function () {
    $set = CategorySet::factory()->for($this->user)->create();
    $coffee = Category::factory()->for($set)->create(['name' => 'Coffee']);
    $book = Book::factory()->for($this->user)->create(['category_set_id' => $set->id]);
    $latte = Transaction::factory()->for(Account::factory()->inBook($book))->create([
        'category_primary' => 'FOOD_AND_DRINK',
        'category_detailed' => 'FOOD_AND_DRINK_COFFEE',
    ]);

    manageCategories($set)
        ->callAction(TestAction::make('edit')->table($coffee), data: ['plaid_categories' => ['FOOD_AND_DRINK_COFFEE']])
        ->assertHasNoFormErrors();

    expect($coffee->fresh()->plaid_categories)->toBe(['FOOD_AND_DRINK_COFFEE'])
        ->and($latte->fresh()->category_id)->toBe($coffee->id);
});
