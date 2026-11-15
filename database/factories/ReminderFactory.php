<?php

namespace Database\Factories;

use App\Models\Recurring;
use App\Models\Reminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recurring_id' => Recurring::factory(),
            'days_before' => 3,
            'repeats' => false,
            'note' => null,
            'reminded_through' => null,
        ];
    }

    public function repeating(): static
    {
        return $this->state(fn () => ['repeats' => true]);
    }
}
