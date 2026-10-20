<?php

namespace Database\Factories\Authz;

use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleInclusion>
 */
class RoleInclusionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_role_id' => Role::factory(),
            'child_role_id' => Role::factory(),
        ];
    }
}
