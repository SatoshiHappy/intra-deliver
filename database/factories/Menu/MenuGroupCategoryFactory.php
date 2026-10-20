<?php

namespace Database\Factories\Menu;

use App\Models\Menu\MenuGroupCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuGroupCategory>
 */
class MenuGroupCategoryFactory extends Factory
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
            'is_selectable' => false,
            'is_editable' => false,
        ];
    }
}
