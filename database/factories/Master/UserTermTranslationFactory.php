<?php

namespace Database\Factories\Master;

use App\Models\Master\Locale;
use App\Models\Master\UserTerm;
use App\Models\Master\UserTermTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserTermTranslation>
 */
class UserTermTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_term_id' => UserTerm::factory(),
            'locale_id' => Locale::factory(),
            'name' => fake()->name(),
        ];
    }
}
