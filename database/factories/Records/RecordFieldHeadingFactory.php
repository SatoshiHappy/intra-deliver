<?php

namespace Database\Factories\Records;

use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordFieldHeading>
 */
class RecordFieldHeadingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_module_id' => RecordModule::factory(),
            'name' => fake()->words(2, true),
            'sort_key' => 0,
        ];
    }
}
