<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\DeleteModuleCategory;
use App\Actions\Records\RenameModuleCategory;
use App\Actions\Records\ReorderModuleCategories;
use App\Actions\Records\ReorderRecordModuleItems;
use App\Actions\Records\SetModuleCategoryModules;
use App\Actions\Records\UpdateRecordModule;
use App\Models\Menu\Menu;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReadsModuleCategories;
use Tests\Support\ReadsRecordModuleMenus;
use Tests\Support\TransactionalTestCase;

/**
 * 分類のサイトマップ側のフォルダが欠けたとき(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.1、画面と検査.md 6 節の「配置」の後半):
 * 分類 ID を受ける業務処理(名前を変える・分類を並べる・分類の中を並べる・その分類を選ぶ categories・分類の中を揃える codes = 3.10)は 422 にならず、サイトマップ側のフォルダを
 * global-nav-main のフォルダの写し(表示名・icon_32)として作り直し、両木の分類の並びを global-nav-main の順で 10 + 位置に揃える。
 * 消す業務処理はサイトマップ側を飛ばす。テストでサイトマップ側のフォルダを消して(業務処理を通さない書き込み)作る。
 */
final class ModuleCategoryMissingSitemapFolderTest extends TransactionalTestCase
{
    use ReadsModuleCategories;
    use ReadsRecordModuleMenus;

    /**
     * 分類 ID(と、ほかの分類 ID)を受けて、その分類を指す業務処理を呼ぶ。
     *
     * @return array<string, array{Closure(string, string): mixed}>
     */
    public static function actionsOnTheCategory(): array
    {
        return [
            '名前を変える' => [fn (string $categoryId) => app(RenameModuleCategory::class)->execute($categoryId, ['name' => '営業'])],
            '分類を並べる' => [fn (string $categoryId, string $other) => app(ReorderModuleCategories::class)->execute([$other, $categoryId])],
            '分類の中を並べる' => [fn (string $categoryId) => app(ReorderRecordModuleItems::class)->execute($categoryId, [])],
            'categories で選ぶ' => [fn (string $categoryId) => app(UpdateRecordModule::class)->execute('project', ['categories' => [$categoryId]])],
            '分類の中を揃える' => [fn (string $categoryId) => app(SetModuleCategoryModules::class)->execute($categoryId, ['project'])],
        ];
    }

    /**
     * @param  Closure(string, string): mixed  $action
     */
    #[DataProvider('actionsOnTheCategory')]
    public function test_the_sitemap_folder_is_recreated_as_a_copy_and_both_trees_are_ordered_by_the_global_navigation(Closure $action): void
    {
        $other = $this->createCategory('総務');
        $categoryId = $this->createCategory('営業');
        Menu::query()->where('code', "sitemap-main-cat-{$other}")->update(['sort_number' => 50]);
        Menu::query()->where('code', "sitemap-main-cat-{$categoryId}")->delete();
        $global = $this->display("global-nav-main-cat-{$categoryId}");

        $action($categoryId, $other);

        $this->assertSame($global, $this->display("sitemap-main-cat-{$categoryId}"));
        $order = $this->categoryOrder();
        $this->assertSame($order['global-nav-main'], $order['sitemap-main']);
        $this->update('project', ['categories' => [$categoryId]]);
        $this->assertSame(["global-nav-main-cat-{$categoryId}-project", "sitemap-main-cat-{$categoryId}-project"], $this->placements('project'));
    }

    public function test_deleting_skips_the_missing_sitemap_folder_and_returns_the_module_to_the_direct_items_of_both_trees(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['categories' => [$categoryId]]);
        Menu::query()->where('code', "sitemap-main-cat-{$categoryId}-project")->delete();
        Menu::query()->where('code', "sitemap-main-cat-{$categoryId}")->delete();

        app(DeleteModuleCategory::class)->execute($categoryId);

        $this->assertNull(Menu::query()->where('code', "global-nav-main-cat-{$categoryId}")->first());
        $this->assertSame(['global-nav-main-records-project', 'sitemap-main-records-project'], $this->placements('project'));
    }

    public function test_creating_and_deleting_while_missing_then_recreating_aligns_the_order_of_both_trees(): void
    {
        $first = $this->createCategory('営業');
        $missing = $this->createCategory('総務');
        $deleted = $this->createCategory('経理');
        Menu::query()->where('code', "sitemap-main-cat-{$missing}")->delete();
        $added = $this->createCategory('人事');
        app(DeleteModuleCategory::class)->execute($deleted);

        app(RenameModuleCategory::class)->execute($missing, ['name' => '総務部']);

        $expected = [$first => 10, $missing => 11, $added => 12];
        $this->assertSame(['global-nav-main' => $expected, 'sitemap-main' => $expected], $this->categoryOrder());
    }
}
