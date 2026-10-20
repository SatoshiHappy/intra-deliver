<?php

namespace Database\Factories\Authz;

use App\Models\Authz\Role;
use App\Models\Authz\UserRole;
use App\Models\Master\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRole>
 */
class UserRoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'start_date' => '2020-01-01',
            'end_date' => null,
            'is_deleted' => false,
        ];
    }
}
