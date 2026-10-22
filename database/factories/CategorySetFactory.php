<?php

namespace Database\Factories;

use App\Enums\BookKind;
use App\Models\CategorySet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategorySet>
 */
class CategorySetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'kind' => fake()->randomElement(BookKind::cases()),
        ];
    }

    public function business(): static
    {
        return $this->state(['kind' => BookKind::Business]);
    }

    public function personal(): static
    {
        return $this->state(['kind' => BookKind::Personal]);
    }
}
