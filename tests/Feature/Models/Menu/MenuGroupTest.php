<?php

namespace Tests\Feature\Models\Menu;

use App\Models\Menu\MenuGroup;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * メニューグループ(docs の 設計/メニュー/表.md 1.2: 1 つのメニューをルートに持つグループは 1 つ。グループ ID はルートのメニューの code)。
 */
final class MenuGroupTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_a_second_group_for_the_same_root_menu(): void
    {
        $group = MenuGroup::factory()->create();

        $this->assertRejectedBy('menu_groups_menu_id_unique', fn () => MenuGroup::factory()->create(['menu_id' => $group->menu_id]));
    }
}
