<?php

namespace Database\Factories\Menu;

use App\Models\Menu\Menu;
use App\Models\Menu\MenuArgument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuArgument>
 */
class MenuArgumentFactory extends Factory
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
            'name' => fake()->unique()->bothify('arg####'),
            'value' => fake()->word(),
        ];
    }
}
