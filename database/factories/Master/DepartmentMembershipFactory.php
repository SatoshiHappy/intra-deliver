<?php

namespace Database\Factories\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentMembership>
 */
class DepartmentMembershipFactory extends Factory
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
            'department_id' => fn (array $attributes) => Department::factory()->state(['department_set_id' => $attributes['department_set_id']]),
            'user_id' => User::factory(),
            'start_date' => '2020-01-01',
            'end_date' => null,
            'is_deleted' => false,
            'is_main' => false,
            'sort_key' => 0,
        ];
    }
}
