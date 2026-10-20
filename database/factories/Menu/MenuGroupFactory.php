<?php

namespace Database\Factories\Menu;

use App\Models\Menu\Menu;
use App\Models\Menu\MenuGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuGroup>
 */
class MenuGroupFactory extends Factory
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
        ];
    }
}
