<?php

namespace Database\Factories\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentInclusion>
 */
class DepartmentInclusionFactory extends Factory
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
            'parent_department_id' => fn (array $attributes) => Department::factory()->state(['department_set_id' => $attributes['department_set_id']]),
            'department_id' => fn (array $attributes) => Department::factory()->state(['department_set_id' => $attributes['department_set_id']]),
            'start_date' => '2020-01-01',
            'end_date' => null,
            'is_deleted' => false,
            'sort_key' => 0,
        ];
    }
}
