<?php

namespace Database\Factories\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
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
            'is_root' => false,
            'sort_key' => 0,
        ];
    }
}
