<?php

namespace Database\Factories\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Models\Master\DepartmentSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentMembershipPost>
 */
class DepartmentMembershipPostFactory extends Factory
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
            'department_membership_id' => fn (array $attributes) => DepartmentMembership::factory()->state(['department_set_id' => $attributes['department_set_id']]),
            'company_post_id' => fn (array $attributes) => CompanyPost::factory()->state(['department_set_id' => $attributes['department_set_id']]),
        ];
    }
}
