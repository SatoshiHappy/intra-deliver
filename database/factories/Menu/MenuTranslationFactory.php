<?php

namespace Database\Factories\Menu;

use App\Models\Master\Locale;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuTranslation>
 */
class MenuTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_id' => Menu::factory(),
            'locale_id' => Locale::factory(),
            'display_name' => fake()->words(2, true),
        ];
    }
}
