<?php

namespace Database\Factories\Menu;

use App\Models\Master\Locale;
use App\Models\Menu\MenuGroupCategory;
use App\Models\Menu\MenuGroupCategoryTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuGroupCategoryTranslation>
 */
class MenuGroupCategoryTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_group_category_id' => MenuGroupCategory::factory(),
            'locale_id' => Locale::factory(),
            'display_name' => fake()->words(2, true),
        ];
    }
}
