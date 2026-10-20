<?php

namespace Database\Factories\Menu;

use App\Enums\Menu\MenuMethod;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
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
            'parent_id' => null,
            'sort_number' => 0,
            'type' => MenuType::Folder,
            'url' => null,
            'method' => MenuMethod::Get,
            'is_iframe_used' => false,
            'is_popup_used' => false,
        ];
    }

    /**
     * 親のフォルダの下のメニューアイテムにする。
     */
    public function item(string $url = '/'): static
    {
        return $this->state(fn (): array => ['parent_id' => Menu::factory(), 'type' => MenuType::Item, 'url' => $url]);
    }

    /**
     * コピーで作ったメニューにする(コピー元を作る)。
     */
    public function copied(): static
    {
        return $this->state(fn (): array => ['original_menu_id' => Menu::factory()]);
    }
}
