<?php

namespace Database\Factories\Menu;

use App\Models\Menu\MenuGroup;
use App\Models\Menu\MenuGroupCategory;
use App\Models\Menu\MenuGroupCategoryInclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuGroupCategoryInclusion>
 */
class MenuGroupCategoryInclusionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_group_id' => MenuGroup::factory(),
            'menu_group_category_id' => MenuGroupCategory::factory(),
        ];
    }
}
