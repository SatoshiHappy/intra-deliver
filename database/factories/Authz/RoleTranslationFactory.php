<?php

namespace Database\Factories\Authz;

use App\Models\Authz\Role;
use App\Models\Authz\RoleTranslation;
use App\Models\Master\Locale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleTranslation>
 */
class RoleTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'locale_id' => Locale::factory(),
            'display_name' => fake()->words(2, true),
        ];
    }
}
