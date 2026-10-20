<?php

namespace Tests\Feature\Models\Menu;

use App\Models\Menu\MenuArgument;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * メニューの引数(docs の 設計/メニュー/表.md 1.5: 引数名は空にしない、1 つのメニューの 1 つの引数名に値は 1 つ)。
 */
final class MenuArgumentTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_an_empty_name(): void
    {
        $this->assertRejectedBy('menu_arguments_name_check', fn () => MenuArgument::factory()->create(['name' => '']));
    }

    public function test_rejects_the_same_name_twice_for_a_menu_and_accepts_it_for_another_menu(): void
    {
        $argument = MenuArgument::factory()->create();

        $this->assertRejectedBy('menu_arguments_menu_id_name_unique', fn () => MenuArgument::factory()->create($argument->only(['menu_id', 'name'])));
        $this->assertModelExists(MenuArgument::factory()->create(['name' => $argument->name]));
    }
}
