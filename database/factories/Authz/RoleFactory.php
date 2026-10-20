<?php

namespace Database\Factories\Authz;

use App\Models\Authz\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('????####'),
            'name' => fake()->unique()->regexify('[a-z]{4}_[a-z]{4}'),
            'category' => null,
            'notes' => null,
            'sort_key' => 0,
        ];
    }
}
