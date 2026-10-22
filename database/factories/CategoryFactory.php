<?php

namespace Database\Factories;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\CategorySet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_set_id' => CategorySet::factory(),
            'name' => Str::ucfirst(fake()->unique()->words(2, true)),
            'type' => CategoryType::Expense,
        ];
    }

    public function income(): static
    {
        return $this->state(['type' => CategoryType::Income]);
    }

    public function childOf(Category $parent): static
    {
        return $this->state([
            'category_set_id' => $parent->category_set_id,
            'parent_id' => $parent->getKey(),
        ]);
    }
}
