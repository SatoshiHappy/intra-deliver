<?php

namespace Database\Factories\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordFieldOption>
 */
class RecordFieldOptionFactory extends Factory
{
    /**
     * Define the model's default state(選択の項目の選択肢).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_field_id' => RecordField::factory()->state(['type' => RecordFieldType::Choice]),
            'code' => fake()->unique()->bothify('option_????##'),
            'name' => fake()->word(),
            'sort_key' => 0,
        ];
    }
}
