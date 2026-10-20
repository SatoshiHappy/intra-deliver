<?php

namespace Tests\Feature\Models\Menu;

use App\Models\Menu\MenuGroupCategory;
use App\Models\Menu\MenuGroupCategoryInclusion;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * メニューグループのカテゴリへの所属(docs の 設計/メニュー/表.md 1.3: 同じ組は 1 つ。1 つのグループは複数のカテゴリに属せる)。
 */
final class MenuGroupCategoryInclusionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_the_same_group_in_the_same_category_twice(): void
    {
        $inclusion = MenuGroupCategoryInclusion::factory()->create();

        $this->assertRejectedBy(
            'menu_group_category_inclusions_menu_group_id_menu_group_category_id_unique',
            fn () => MenuGroupCategoryInclusion::factory()->create($inclusion->only(['menu_group_id', 'menu_group_category_id'])),
        );
    }

    public function test_accepts_a_group_in_several_categories(): void
    {
        $inclusion = MenuGroupCategoryInclusion::factory()->create();

        $other = MenuGroupCategoryInclusion::factory()->create(['menu_group_id' => $inclusion->menu_group_id, 'menu_group_category_id' => MenuGroupCategory::factory()]);

        $this->assertModelExists($other);
    }
}
