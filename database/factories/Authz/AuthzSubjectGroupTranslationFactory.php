<?php

namespace Database\Factories\Authz;

use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Master\Locale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthzSubjectGroupTranslation>
 */
class AuthzSubjectGroupTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'authz_subject_group_id' => AuthzSubjectGroup::factory(),
            'locale_id' => Locale::factory(),
            'display_name' => fake()->words(2, true),
            'description' => null,
        ];
    }
}
