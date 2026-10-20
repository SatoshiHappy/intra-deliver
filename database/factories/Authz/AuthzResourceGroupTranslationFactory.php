<?php

namespace Database\Factories\Authz;

use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Master\Locale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthzResourceGroupTranslation>
 */
class AuthzResourceGroupTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'authz_resource_group_id' => AuthzResourceGroup::factory(),
            'locale_id' => Locale::factory(),
            'display_name' => fake()->words(2, true),
            'description' => null,
        ];
    }
}
