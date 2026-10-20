<?php

namespace Database\Factories\Master;

use App\Models\Master\CompanyPostTerm;
use App\Models\Master\CompanyPostTermTranslation;
use App\Models\Master\Locale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyPostTermTranslation>
 */
class CompanyPostTermTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_post_term_id' => CompanyPostTerm::factory(),
            'locale_id' => Locale::factory(),
            'name' => fake()->jobTitle(),
        ];
    }
}
