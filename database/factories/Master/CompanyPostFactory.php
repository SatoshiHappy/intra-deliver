<?php

namespace Database\Factories\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\DepartmentSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyPost>
 */
class CompanyPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_set_id' => DepartmentSet::factory(),
            'code' => fake()->unique()->bothify('????####'),
            'rank' => fake()->numberBetween(1, 100),
            'sort_key' => 0,
        ];
    }
}
