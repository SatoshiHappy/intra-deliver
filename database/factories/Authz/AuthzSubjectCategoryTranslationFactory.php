<?php

namespace Database\Factories\Authz;

use App\Models\Authz\AuthzSubjectCategory;
use App\Models\Authz\AuthzSubjectCategoryTranslation;
use App\Models\Master\Locale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthzSubjectCategoryTranslation>
 */
class AuthzSubjectCategoryTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'authz_subject_category_id' => AuthzSubjectCategory::factory(),
            'locale_id' => Locale::factory(),
            'display_name' => fake()->words(2, true),
        ];
    }
}
