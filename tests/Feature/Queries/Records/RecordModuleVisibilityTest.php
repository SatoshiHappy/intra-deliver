<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\CreateRecordModule;
use App\Actions\Records\UpdateRecordModule;
use App\Enums\Menu\MenuType;
use App\Enums\Records\RecordModuleVisibilityChange;
use App\Models\Menu\Menu;
use App\Models\Records\RecordModule;
use App\Queries\Records\ModuleCategoryPageQuery;
use App\Queries\Records\RecordModuleVisibility;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsModuleCategories;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールの表示の状態(docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 5.1、モジュール分類/README.md 1.4): アイテムは目標の親の子に
 * あるときだけ数え、モジュール分類の画面(画面と検査.md 4.4)とアイテムを揃える業務処理(モジュール分類/業務処理.md 3.7)も同じ読み方をする。
 * 初期データのプロジェクト(直下)と課題(直下)、申請書の業務モジュール 2 つ(非表示 = ADR 0031。グループ「ワークフロー」のアイテムは数えない)を使う。
 */
final class RecordModuleVisibilityTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsModuleCategories;

    /**
     * 直下のメニュー ID のアイテムが、global-nav-main のルートの子でない(業務処理を通さない書き込みでだけ起きる)。
     */
    #[TestWith(['global-nav-main'], 'global-nav-main のルート以外のフォルダの下')]
    #[TestWith(['sitemap-main'], '別のグループの木')]
    public function test_a_direct_item_outside_the_root_of_global_nav_main_does_not_make_the_module_visible(string $treeOfTheFolder): void
    {
        $this->moveUnderOtherFolder('global-nav-main-records-project', $treeOfTheFolder);

        $this->assertFalse(app(RecordModuleVisibility::class)->isVisible('project'));
        $this->assertSame(['project_issue'], app(RecordModuleVisibility::class)->visibleCodes(['project', 'project_issue']));
        $props = app(ModuleCategoryPageQuery::class)->props();
        $this->assertSame([], $props['categories']);
        $this->assertSame([
            ['code' => 'project_issue', 'name' => '課題', 'isVisible' => true],
            ['code' => 'meeting', 'name' => '会議', 'isVisible' => true],
            ['code' => 'issue_resolution_application', 'name' => '課題の対応の申請', 'isVisible' => false],
            ['code' => 'project', 'name' => 'プロジェクト', 'isVisible' => false],
            ['code' => 'project_plan_application', 'name' => 'プロジェクト計画の申請', 'isVisible' => false],
        ], $props['uncategorized']);
    }

    /**
     * ずれたアイテムを消して作り直すので、1 回の更新で表示中になり、結果も「表示にした」と一致する。正しい所にあるサイトマップのアイテムは作り直さない。
     */
    #[TestWith(['global-nav-main'], 'global-nav-main のルート以外のフォルダの下')]
    #[TestWith(['sitemap-main'], '別のグループの木')]
    public function test_showing_puts_a_misplaced_direct_item_back_under_the_root_in_one_update(string $treeOfTheFolder): void
    {
        $folder = $this->moveUnderOtherFolder('global-nav-main-records-project', $treeOfTheFolder);
        $sitemapItemId = Menu::query()->where('code', 'sitemap-main-records-project')->sole()->id;

        $this->assertSame(RecordModuleVisibilityChange::Shown, app(UpdateRecordModule::class)->execute('project', ['is_visible' => true]));

        $this->assertTrue(app(RecordModuleVisibility::class)->isVisible('project'));
        $this->assertArrayHasKey('global-nav-main-records-project', $this->children('global-nav-main'));
        $this->assertSame([], $this->children($folder->code));
        $this->assertSame($sitemapItemId, Menu::query()->where('code', 'sitemap-main-records-project')->sole()->id);
    }

    public function test_showing_puts_a_misplaced_item_of_a_category_back_into_its_folder(): void
    {
        $categoryId = $this->createCategory('営業');
        app(UpdateRecordModule::class)->execute('project', ['categories' => [$categoryId]]);
        $itemCode = "global-nav-main-cat-{$categoryId}-project";
        $folder = $this->moveUnderOtherFolder($itemCode, 'global-nav-main');
        $this->assertFalse(app(RecordModuleVisibility::class)->isVisible('project'));

        $this->assertSame(RecordModuleVisibilityChange::Shown, app(UpdateRecordModule::class)->execute('project', ['is_visible' => true, 'categories' => [$categoryId]]));

        $this->assertTrue(app(RecordModuleVisibility::class)->isVisible('project'));
        $this->assertArrayHasKey($itemCode, $this->children("global-nav-main-cat-{$categoryId}"));
        $this->assertSame([], $this->children($folder->code));
    }

    /**
     * 消す対象が子を持つフォルダなら、メニューを消す業務処理が拒み(キー is_visible)、フォルダとその子を消さない。
     */
    public function test_showing_rejects_a_misplaced_folder_with_children_and_removes_nothing(): void
    {
        Menu::query()->where('code', 'global-nav-main-records-project')->delete();
        $misplaced = Menu::factory()->create(['code' => 'global-nav-main-records-project', 'type' => MenuType::Folder, 'parent_id' => Menu::query()->where('code', 'sitemap-main')->sole()->id]);
        Menu::factory()->item()->create(['code' => 'example-child', 'parent_id' => $misplaced->id]);

        $errors = $this->validationErrors(fn () => app(UpdateRecordModule::class)->execute('project', ['is_visible' => true]));

        $this->assertSame(['is_visible' => '子のあるメニューは削除できません: global-nav-main-records-project'], $errors);
        $this->assertSame(['example-child'], array_keys($this->children('global-nav-main-records-project')));
        $this->assertFalse(app(RecordModuleVisibility::class)->isVisible('project'));
    }

    public function test_creating_a_module_replaces_a_misplaced_item_with_the_same_id(): void
    {
        $folder = Menu::factory()->create(['code' => 'global-nav-main-other-folder', 'type' => MenuType::Folder, 'parent_id' => Menu::query()->where('code', 'global-nav-main')->sole()->id]);
        Menu::factory()->item()->create(['code' => 'global-nav-main-records-equipment', 'parent_id' => $folder->id]);

        app(CreateRecordModule::class)->execute(['code' => 'equipment', 'name' => '備品']);

        $this->assertTrue(app(RecordModuleVisibility::class)->isVisible('equipment'));
        $this->assertSame([], $this->children($folder->code));
    }

    public function test_every_module_appears_on_the_category_page_on_the_side_of_its_visibility(): void
    {
        $categoryId = $this->createCategory('営業');
        app(UpdateRecordModule::class)->execute('project_issue', ['is_visible' => true, 'categories' => [$categoryId]]);
        RecordModule::factory()->create(['code' => 'hidden_one', 'name' => '非表示']);

        $applications = ['project_plan_application', 'issue_resolution_application'];
        $codes = ['project', 'project_issue', 'meeting', ...$applications, 'hidden_one'];
        $this->assertEqualsCanonicalizing($codes, RecordModule::query()->whereNull('parent_record_module_id')->pluck('code')->all());
        $visible = app(RecordModuleVisibility::class)->visibleCodes($codes);
        $props = app(ModuleCategoryPageQuery::class)->props();
        $shown = [
            ...array_merge(...array_map(fn (array $category) => array_column($category['modules'], 'code'), $props['categories'])),
            ...array_column(array_filter($props['uncategorized'], fn (array $module) => $module['isVisible']), 'code'),
        ];
        $hidden = array_column(array_filter($props['uncategorized'], fn (array $module) => ! $module['isVisible']), 'code');

        $this->assertEqualsCanonicalizing(['project', 'project_issue', 'meeting'], $visible);
        foreach ($codes as $code) {
            $this->assertSame(in_array($code, $visible, true) ? [true, false] : [false, true], [in_array($code, $shown, true), in_array($code, $hidden, true)], $code);
        }
    }

    /**
     * アイテムを、その木のルートの下に作った分類でないフォルダの下に移す(業務処理を通さない書き込み)。
     */
    private function moveUnderOtherFolder(string $itemCode, string $treeOfTheFolder): Menu
    {
        $folder = Menu::factory()->create([
            'code' => "{$treeOfTheFolder}-other-folder",
            'type' => MenuType::Folder,
            'parent_id' => Menu::query()->where('code', $treeOfTheFolder)->sole()->id,
        ]);
        Menu::query()->where('code', $itemCode)->update(['parent_id' => $folder->id]);

        return $folder;
    }
}
