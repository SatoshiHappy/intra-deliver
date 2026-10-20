<?php

namespace Database\Factories\Master;

use App\Models\Master\DepartmentTerm;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentTermTranslation>
 */
class DepartmentTermTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_term_id' => DepartmentTerm::factory(),
            'locale_id' => Locale::factory(),
            'name' => fake()->company(),
        ];
    }
}
