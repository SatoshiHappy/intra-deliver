<?php

namespace Database\Factories\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentTerm>
 */
class DepartmentTermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'start_date' => '2020-01-01',
            'end_date' => null,
            'is_deleted' => false,
        ];
    }
}
