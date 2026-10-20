<?php

namespace Database\Factories\Master;

use App\Models\Master\Company;
use App\Models\Master\DepartmentSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentSet>
 */
class DepartmentSetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->bothify('????####'),
            'is_default' => false,
            'sort_key' => 0,
        ];
    }
}
