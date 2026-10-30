<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuItemInput;
use App\Actions\Records\CreateRecordModule;
use App\Actions\Records\SetModuleCategoryModules;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsModuleCategories;
use Tests\Support\ReadsRecordModuleMenus;
use Tests\Support\TransactionalTestCase;

/**
 * 分類の中の業務モジュールを揃える(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.10、画面と検査.md 6 節の「追加の Dialog の口」と
 * 「追加の Dialog の口の巻き戻し」): codes の業務モジュールが 2 木のその分類の末尾に codes の順で入り、ほかの分類に属さないものは直下から消える。外したものは
 * ほかの分類に属さなければ直下の末尾に(分類の中の並びの順)、属せばそちらに残る。今と同じ集合なら何も変わらない。片方の木だけの状態から 1 回で揃う。
 * codes の誤りはキー codes、下位の業務処理の拒否はキー category で、何も変わらない。サイトマップ側のフォルダの作り直しは
 * {@see ModuleCategoryMissingSitemapFolderTest} が見る。初期データのプロジェクト(直下 100)・課題(直下 110)と組み込みの会議(直下 120)を使う
 * (申請書の業務モジュール 2 つは初期データで非表示 = ADR 0031)。
 */
final class SetModuleCategoryModulesTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsModuleCategories;
    use ReadsRecordModuleMenus;

    public function test_the_codes_enter_the_end_of_the_category_in_their_order_and_leave_the_direct_items_only_when_in_no_other_category(): void
    {
        app(CreateRecordModule::class)->execute(['code' => 'example_extra', 'name' => '備品']);
        $categoryId = $this->createCategory('営業');
        $other = $this->createCategory('総務');
        $this->update('example_extra', ['categories' => [$categoryId]]);
        $this->update('project_issue', ['categories' => [$other]]);

        $this->set($categoryId, ['project_issue', 'example_extra', 'project']);

        foreach (WorkMenuGroup::cases() as $group) {
            $this->assertSame([
                $group->categoryItemCode($categoryId, 'example_extra') => 100,
                $group->categoryItemCode($categoryId, 'project_issue') => 110,
                $group->categoryItemCode($categoryId, 'project') => 120,
            ], $this->children($group->categoryFolderCode($categoryId)));
            $this->assertSame([$group->categoryItemCode($other, 'project_issue') => 100], $this->children($group->categoryFolderCode($other)));
            $this->assertSame([
                "{$group->value}-records-meeting" => 120,
            ], array_filter($this->children($group->value), fn (int $sortNumber) => $sortNumber >= 100));
        }
    }

    public function test_the_unchecked_modules_return_to_the_end_of_the_direct_items_in_the_order_inside_or_stay_in_the_other_category(): void
    {
        app(CreateRecordModule::class)->execute(['code' => 'example_extra', 'name' => '備品']);
        $categoryId = $this->createCategory('営業');
        $other = $this->createCategory('総務');
        $this->set($categoryId, ['project_issue', 'project', 'example_extra']);
        $this->update('project_issue', ['categories' => [$categoryId, $other]]);

        $this->set($categoryId, ['example_extra']);

        foreach (WorkMenuGroup::cases() as $group) {
            // 残るものの位置は変わらない
            $this->assertSame([$group->categoryItemCode($categoryId, 'example_extra') => 120], $this->children($group->categoryFolderCode($categoryId)));
            $this->assertSame([$group->categoryItemCode($other, 'project_issue') => 100], $this->children($group->categoryFolderCode($other)));
            $this->assertSame([
                "{$group->value}-records-meeting" => 120,
                "{$group->value}-records-project" => 130,
            ], array_filter($this->children($group->value), fn (int $sortNumber) => $sortNumber >= 100));
        }

        $this->set($categoryId, []);

        foreach (WorkMenuGroup::cases() as $group) {
            $this->assertSame([], $this->children($group->categoryFolderCode($categoryId)));
            $this->assertSame([
                "{$group->value}-records-meeting" => 120,
                "{$group->value}-records-project" => 130,
                "{$group->value}-records-example_extra" => 140,
            ], array_filter($this->children($group->value), fn (int $sortNumber) => $sortNumber >= 100));
        }
    }

    public function test_the_same_set_succeeds_without_changing_anything(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->set($categoryId, ['project_issue', 'project']);
        $before = $this->authorizationAndMenus();

        $this->set($categoryId, ['project', 'project_issue']);

        $this->assertSame($before, $this->authorizationAndMenus());
    }

    public function test_one_call_aligns_both_trees_from_items_only_in_one_tree(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->set($categoryId, ['project']);
        // プロジェクトのサイトマップ側のアイテムが欠け、サイトマップ側にだけ課題のはぐれたアイテムがある(課題は直下に表示中)
        Menu::query()->where('code', "sitemap-main-cat-{$categoryId}-project")->delete();
        $folder = Menu::query()->where('code', "sitemap-main-cat-{$categoryId}")->sole();
        app(AddMenu::class)->execute($folder->id, new MenuItemInput("sitemap-main-cat-{$categoryId}-project_issue", '/records/data/project_issue', ['ja' => '課題']));

        $this->set($categoryId, ['project']);

        $this->assertSame(["global-nav-main-cat-{$categoryId}-project", "sitemap-main-cat-{$categoryId}-project"], $this->placements('project'));
        $this->assertSame(['global-nav-main-records-project_issue', 'sitemap-main-records-project_issue'], $this->placements('project_issue'));
    }

    /**
     * codes の誤り(要素が文字列でない・重なり・無いコード・明細のモジュール・非表示の業務モジュール)はキー codes の 422 で、何も変わらない。
     * list でない封筒は入口が拒むが、業務処理も要素の形として同じ文言で拒む。
     *
     * @param  array<array-key, mixed>  $codes
     */
    #[TestWith([['x' => 'project'], '業務モジュールの一覧の形が正しくありません'])]
    #[TestWith([['project', 1], '業務モジュールの一覧の形が正しくありません'])]
    #[TestWith([['project', 'project'], '同じ業務モジュールが重複しています'])]
    #[TestWith([['project', 'example_nothing'], '業務モジュールが見つかりません。画面を再読み込みしてから再度操作してください'])]
    #[TestWith([['project_milestone'], '明細のモジュールにはモジュール分類を設定できません'])]
    #[TestWith([['project', 'project_issue'], 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません'])]
    public function test_a_wrong_code_is_rejected_with_the_key_codes_and_nothing_changes(array $codes, string $message): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project_issue', ['is_visible' => false]);
        $before = $this->authorizationAndMenus();

        $errors = $this->validationErrors(fn () => $this->set($categoryId, $codes));

        $this->assertSame(['codes' => $message], $errors);
        $this->assertSame($before, $this->authorizationAndMenus());
    }

    public function test_a_rejection_of_the_menu_after_the_first_module_rolls_back_everything_with_the_key_category(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->set($categoryId, ['project', 'project_issue']);
        // 2 つ目のサイトマップ側の分類の中のアイテムをコピーの元にする(メニューを消す業務処理が拒む)
        $item = Menu::query()->where('code', "sitemap-main-cat-{$categoryId}-project_issue")->sole();
        Menu::factory()->item()->create(['code' => 'example-copy', 'original_menu_id' => $item->id]);
        $before = $this->authorizationAndMenus();

        $errors = $this->validationErrors(fn () => $this->set($categoryId, []));

        $this->assertSame(['category'], array_keys($errors));
        $this->assertSame($before, $this->authorizationAndMenus());
        $this->assertSame(["global-nav-main-cat-{$categoryId}-project", "sitemap-main-cat-{$categoryId}-project"], $this->placements('project'));
    }

    /**
     * @param  array<array-key, mixed>  $codes
     */
    private function set(string $categoryId, array $codes): void
    {
        app(SetModuleCategoryModules::class)->execute($categoryId, $codes);
    }
}
