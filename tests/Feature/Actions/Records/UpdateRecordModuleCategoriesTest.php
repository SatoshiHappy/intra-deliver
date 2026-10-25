<?php

namespace Tests\Feature\Actions\Records;

use App\Enums\Menu\WorkMenuGroup;
use App\Enums\Records\RecordModuleVisibilityChange;
use App\Models\Master\Locale;
use App\Models\Menu\Menu;
use App\Queries\Records\RecordAdminQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsModuleCategories;
use Tests\Support\ReadsRecordModuleMenus;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールを変える業務処理の categories(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.7、項目と規則/規則と業務処理/失敗と定義の変更.md 10 節・11 節、
 * モジュール分類/画面と検査.md 6 節の「配置」): 選んだ分類ごとに 2 木の分類の中にアイテムを作り、直下のアイテムを消す。空にすると直下の末尾に 1 つ。非表示にすると
 * 直下とすべての分類の中のアイテムが消え、表示に戻すと直下に 1 つ(分類は戻らない)。名前・アイコンの変更はすべてのアイテムに及ぶ。入力の誤りは
 * キー categories の 422 で何も変わらない。表示中の判定は分類の中のアイテムでも真。初期データのプロジェクト(直下 100)・課題(直下 110)と
 * 組み込みの会議(直下 120)、申請書の業務モジュール 2 つ(直下 130・140)を使う。
 */
final class UpdateRecordModuleCategoriesTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsModuleCategories;
    use ReadsRecordModuleMenus;

    public function test_choosing_two_categories_places_an_item_in_each_of_both_trees_and_removes_the_direct_items(): void
    {
        $first = $this->createCategory('営業');
        $second = $this->createCategory('総務');

        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $this->update('project', ['categories' => [$second, $first]]));

        $this->assertSame([
            "global-nav-main-cat-{$first}-project", "global-nav-main-cat-{$second}-project",
            "sitemap-main-cat-{$first}-project", "sitemap-main-cat-{$second}-project",
        ], $this->placements('project'));
        $names = array_fill_keys(Locale::query()->orderBy('code')->pluck('code')->all(), 'プロジェクト');
        $this->assertSame([$names, 'folder-kanban'], $this->display("sitemap-main-cat-{$second}-project"));
        $this->assertSame([$first, $second], $this->categoryIds('project'));
    }

    public function test_an_empty_array_returns_the_module_to_the_end_of_the_direct_items(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['categories' => [$categoryId]]);

        $this->update('project', ['categories' => []]);

        $this->assertSame(['global-nav-main-records-project', 'sitemap-main-records-project'], $this->placements('project'));
        $sortNumbers = array_map(fn (array $row) => $row['sort_number'], $this->itemRows('project'));
        $this->assertSame([
            'global-nav-main-records-project' => 150, 'sitemap-main-records-project' => 150,
        ], $sortNumbers);
        $this->assertSame([], $this->children("global-nav-main-cat-{$categoryId}"));
    }

    public function test_hiding_removes_every_item_and_showing_places_one_direct_item_without_the_categories(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['categories' => [$categoryId]]);

        $this->assertSame(RecordModuleVisibilityChange::Hidden, $this->update('project', ['is_visible' => false, 'categories' => []]));
        $this->assertSame([], $this->placements('project'));
        $this->assertSame(RecordModuleVisibilityChange::Shown, $this->update('project', ['is_visible' => true]));

        $this->assertSame(['global-nav-main-records-project', 'sitemap-main-records-project'], $this->placements('project'));
    }

    public function test_showing_with_categories_places_the_items_in_them(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['is_visible' => false]);

        $this->assertSame(RecordModuleVisibilityChange::Shown, $this->update('project', ['is_visible' => true, 'categories' => [$categoryId]]));

        $this->assertSame(["global-nav-main-cat-{$categoryId}-project", "sitemap-main-cat-{$categoryId}-project"], $this->placements('project'));
    }

    public function test_a_module_only_in_a_category_is_visible_in_the_list_and_in_the_change(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['categories' => [$categoryId]]);

        $this->assertTrue(collect(app(RecordAdminQuery::class)->modules())->firstWhere('code', 'project')['isVisible'] ?? null);
        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $this->update('project', ['is_visible' => true]));
        $this->assertSame(["global-nav-main-cat-{$categoryId}-project", "sitemap-main-cat-{$categoryId}-project"], $this->placements('project'));
        $this->assertSame(RecordModuleVisibilityChange::Hidden, $this->update('project', ['is_visible' => false]));
    }

    public function test_the_name_and_the_icon_follow_on_every_item(): void
    {
        $first = $this->createCategory('営業');
        $second = $this->createCategory('総務');
        $this->update('project', ['categories' => [$first, $second]]);

        $this->update('project', ['name' => '案件', 'icon' => 'truck', 'categories' => [$first]]);

        $names = array_fill_keys(Locale::query()->orderBy('code')->pluck('code')->all(), '案件');
        $this->assertSame([
            "global-nav-main-cat-{$first}-project" => [$names, 'truck'],
            "sitemap-main-cat-{$first}-project" => [$names, 'truck'],
        ], array_combine($this->placements('project'), array_map(fn (string $code) => $this->display($code), $this->placements('project'))));
    }

    public function test_a_one_sided_state_is_aligned_in_one_update(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['categories' => [$categoryId]]);
        Menu::query()->where('code', "sitemap-main-cat-{$categoryId}-project")->delete();
        Menu::factory()->create(['code' => 'sitemap-main-records-project', 'parent_id' => Menu::query()->where('code', 'sitemap-main')->value('id'), 'type' => 'item', 'url' => '/records/data/project']);

        $this->update('project', ['categories' => [$categoryId]]);

        $this->assertSame(["global-nav-main-cat-{$categoryId}-project", "sitemap-main-cat-{$categoryId}-project"], $this->placements('project'));
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function rejectedCategories(): array
    {
        return [
            '非表示のまま空でない' => ['project', ['is_visible' => false, 'categories' => ['first']], 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません'],
            '無い分類 ID' => ['project', ['categories' => ['01arz3ndektsv4rrffq69g5fav']], 'モジュール分類が見つかりません。画面を再読み込みしてから再度操作してください'],
            '形の誤り' => ['project', ['categories' => ['First']], 'モジュール分類が見つかりません。画面を再読み込みしてから再度操作してください'],
            '重なり' => ['project', ['categories' => ['first', 'first']], '同じモジュール分類が重複しています'],
            '配列でない' => ['project', ['categories' => 'first'], 'モジュール分類は分類 ID の配列で指定してください'],
            '要素が文字列でない' => ['project', ['categories' => [1]], 'モジュール分類は分類 ID の配列で指定してください'],
            '要素が空文字' => ['project', ['categories' => ['  ']], 'モジュール分類は分類 ID の配列で指定してください'],
            '連想配列' => ['project', ['categories' => ['a' => 'first']], 'モジュール分類は分類 ID の配列で指定してください'],
            '明細のモジュール' => ['project_milestone', ['name' => '節目', 'categories' => []], '明細のモジュールにはモジュール分類を設定できません'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  分類 ID の first は作った分類 ID に置き換える
     */
    #[DataProvider('rejectedCategories')]
    public function test_invalid_categories_are_rejected_with_the_key_categories_and_nothing_changes(string $moduleCode, array $input, string $message): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['is_visible' => false]);
        $this->update('project', ['is_visible' => true]);
        $input = array_map(fn (mixed $value) => is_array($value) ? array_map(fn (mixed $id) => $id === 'first' ? $categoryId : $id, $value) : ($value === 'first' ? $categoryId : $value), $input);
        $before = [$this->moduleValues($moduleCode), $this->authorizationAndMenus()];

        $this->assertSame(['categories' => $message], $this->validationErrors(fn () => $this->update($moduleCode, $input)));

        $this->assertSame($before, [$this->moduleValues($moduleCode), $this->authorizationAndMenus()]);
    }

    public function test_a_hidden_module_rejects_categories_without_is_visible_and_accepts_an_empty_array(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['is_visible' => false]);

        $this->assertSame(['categories' => 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません'], $this->validationErrors(fn () => $this->update('project', ['categories' => [$categoryId]])));
        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $this->update('project', ['categories' => []]));
        $this->assertSame([], $this->placements('project'));
    }

    public function test_a_rejection_of_the_removal_rolls_back_with_the_key_is_visible(): void
    {
        $categoryId = $this->createCategory('営業');
        $item = Menu::query()->where('code', 'sitemap-main-records-project')->sole();
        Menu::factory()->item()->create(['code' => 'example-copy', 'original_menu_id' => $item->id]);
        $before = $this->authorizationAndMenus();

        $errors = $this->validationErrors(fn () => $this->update('project', ['categories' => [$categoryId]]));

        $this->assertSame(['is_visible' => 'コピーの元のメニューは削除できません: sitemap-main-records-project'], $errors);
        $this->assertSame($before, $this->authorizationAndMenus());
    }

    /**
     * 業務モジュールが属する分類 ID(global-nav-main で読む)。
     *
     * @return list<string>
     */
    private function categoryIds(string $moduleCode): array
    {
        return array_values(array_filter(array_map(
            fn (string $code) => WorkMenuGroup::GlobalNavigation->categoryIdOf(substr($code, 0, -strlen("-{$moduleCode}"))),
            $this->placements($moduleCode),
        )));
    }
}
