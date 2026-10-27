<?php

namespace Database\Factories;

use App\Models\Occurrence;
use App\Models\Recurring;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Occurrence>
 */
class OccurrenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recurring_id' => Recurring::factory(),
            'due_on' => today(),
            'transaction_id' => null,
            'matched_by' => null,
            'resolution' => null,
        ];
    }
}
