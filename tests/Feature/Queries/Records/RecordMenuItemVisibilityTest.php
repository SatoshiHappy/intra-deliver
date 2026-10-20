<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\Api\MenuItemRoute;
use App\Queries\Records\RecordMenuItemVisibility;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 業務の一覧のアイテムの追加の判定(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 1.2)。初期データの業務モジュールを使う
 * (project・project_issue = 親の無い業務モジュール、project_milestone = 明細のモジュール)。判定を求める者はゲスト。
 */
final class RecordMenuItemVisibilityTest extends TransactionalTestCase
{
    use UsesSeededRecords;

    public function test_hides_list_items_of_missing_detail_and_unreadable_modules_and_ignores_other_routes(): void
    {
        $this->deny('project', RecordModuleAction::Read);

        $hidden = $this->hiddenItems([
            1 => $this->index('project'),
            2 => $this->index('project_issue'),
            3 => $this->index('no_such_module'),
            4 => $this->index('project_milestone'),
            5 => new MenuItemRoute('records.data.show', ['module' => 'project', 'record' => '1']),
            6 => new MenuItemRoute('menu.sitemap', []),
        ]);

        $this->assertSame([1, 3, 4], $hidden);
    }

    public function test_keeps_the_list_items_of_readable_modules(): void
    {
        $items = [1 => $this->index('project'), 2 => $this->index('project'), 3 => $this->index('project_issue')];

        $this->assertSame([], $this->hiddenItems($items));
    }

    /**
     * 読みを禁止した業務モジュールを指すアイテムは、何件あっても全部隠す。
     */
    public function test_hides_every_item_of_an_unreadable_module(): void
    {
        $this->deny('project_issue', RecordModuleAction::Read);

        $items = [1 => $this->index('project'), 2 => $this->index('project_issue'), 3 => $this->index('project_issue')];

        $this->assertSame([2, 3], $this->hiddenItems($items));
    }

    public function test_hides_nothing_without_items(): void
    {
        $this->assertSame([], $this->hiddenItems([]));
    }

    private function index(string $moduleCode): MenuItemRoute
    {
        return new MenuItemRoute('records.data.index', ['module' => $moduleCode]);
    }

    /**
     * @param  array<int, MenuItemRoute>  $items
     * @return list<int>
     */
    private function hiddenItems(array $items): array
    {
        $date = CarbonImmutable::parse('2026-10-20');
        $hidden = app(RecordMenuItemVisibility::class)->hiddenItems(new Requester(null, null), $items, $date);
        sort($hidden);

        return $hidden;
    }
}
