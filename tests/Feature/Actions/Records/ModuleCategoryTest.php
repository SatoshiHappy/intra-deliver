<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\CreateModuleCategory;
use App\Actions\Records\CreateRecordModule;
use App\Actions\Records\DeleteModuleCategory;
use App\Actions\Records\RenameModuleCategory;
use App\Actions\Records\ReorderModuleCategories;
use App\Actions\Records\ReorderRecordModuleItems;
use App\Enums\Menu\MenuType;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Master\Locale;
use App\Models\Menu\Menu;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsModuleCategories;
use Tests\Support\ReadsRecordModuleMenus;
use Tests\Support\TransactionalTestCase;

/**
 * モジュール分類の持ち方・並び・名前・並び替え(docs の 設計/業務データの入れ物/モジュール分類/README.md 1 節・2 節、業務処理.md 3.2・3.3・3.5・3.6、
 * 画面と検査.md 6 節の「持ち方」「並び」「名前」「権限の組み合わせ」の業務処理の分): 作ると 2 木のルートの直下に分類のフォルダができ、10 + 位置で
 * 初期のアイテム(0〜2)と直下の業務モジュール(100〜)の間に並ぶ。91 個目・名前の誤り・順列でない ids と codes は 422 で何も変わらない。
 * 無い分類の名前の変更・中の並べ替えは ModelNotFoundException で何も変わらない。初期データのプロジェクト(直下 100)・課題(直下 110)と組み込みの会議(直下 120)を使う。
 */
final class ModuleCategoryTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsModuleCategories;
    use ReadsRecordModuleMenus;

    public function test_creating_adds_a_folder_under_the_root_of_both_trees_with_the_name_in_every_locale(): void
    {
        $categoryId = $this->createCategory(' 営業 ');

        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/D', $categoryId);
        $names = array_fill_keys(Locale::query()->orderBy('code')->pluck('code')->all(), '営業');

        foreach (WorkMenuGroup::cases() as $group) {
            $folder = Menu::query()->where('code', "{$group->value}-cat-{$categoryId}")->sole();
            $this->assertSame([MenuType::Folder, null, $group->value], [$folder->type, $folder->url, $folder->parent?->code]);
            $this->assertSame([$names, 'folder'], $this->display($folder->code));
        }
    }

    public function test_the_folders_sort_between_the_initial_items_and_the_direct_modules_and_are_renumbered_on_create_delete_and_reorder(): void
    {
        $first = $this->createCategory('営業');
        $second = $this->createCategory('総務');

        foreach (WorkMenuGroup::cases() as $group) {
            $this->assertSame([
                "{$group->value}-menu-home", "{$group->value}-master-organization-chart", "{$group->value}-menu-sitemap",
                "{$group->value}-cat-{$first}", "{$group->value}-cat-{$second}",
                "{$group->value}-records-project", "{$group->value}-records-project_issue", "{$group->value}-records-meeting",
            ], array_keys($this->children($group->value)));
        }

        $this->assertSame(['global-nav-main' => [$first => 10, $second => 11], 'sitemap-main' => [$first => 10, $second => 11]], $this->categoryOrder());
        $third = $this->createCategory('経理');
        app(ReorderModuleCategories::class)->execute([$third, $first, $second]);
        $this->assertSame(['global-nav-main' => [$third => 10, $first => 11, $second => 12], 'sitemap-main' => [$third => 10, $first => 11, $second => 12]], $this->categoryOrder());
        app(ReorderModuleCategories::class)->execute([$third, $first, $second]);
        app(DeleteModuleCategory::class)->execute($third);
        $this->assertSame(['global-nav-main' => [$first => 10, $second => 11], 'sitemap-main' => [$first => 10, $second => 11]], $this->categoryOrder());
    }

    public function test_the_ninety_first_category_is_rejected_and_nothing_is_added(): void
    {
        foreach (WorkMenuGroup::cases() as $group) {
            $root = Menu::query()->where('code', $group->value)->sole();

            foreach (range(0, 89) as $position) {
                Menu::factory()->create(['code' => $group->categoryFolderCode(sprintf('%026d', $position)), 'parent_id' => $root->id, 'sort_number' => 10 + $position]);
            }
        }

        $menus = Menu::query()->count();

        $this->assertSame(['category' => 'モジュール分類は 90 までです'], $this->validationErrors(fn () => $this->createCategory('営業')));
        $this->assertSame($menus, Menu::query()->count());
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidNames(): array
    {
        return [
            '空白だけ' => ['  ', '名前を入力してください'],
            'null' => [null, '名前を入力してください'],
            '1001 文字' => [str_repeat('あ', 1001), '名前は 1000 文字以内で入力してください'],
            'U+0000' => ["営\0業", '名前に使用できない文字(U+0000)が含まれています'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_an_invalid_name_is_rejected_on_create_and_rename_and_nothing_changes(mixed $name, string $message): void
    {
        $categoryId = $this->createCategory('営業');
        $menus = Menu::query()->count();

        $this->assertSame(['name' => $message], $this->validationErrors(fn () => app(CreateModuleCategory::class)->execute(['name' => $name])));
        $this->assertSame(['name' => $message], $this->validationErrors(fn () => app(RenameModuleCategory::class)->execute($categoryId, ['name' => $name])));
        $this->assertSame($menus, Menu::query()->count());
        $this->assertSame('営業', $this->display("sitemap-main-cat-{$categoryId}")[0]['ja'] ?? null);
    }

    public function test_renaming_replaces_the_names_of_both_folders(): void
    {
        $categoryId = $this->createCategory('営業');

        app(RenameModuleCategory::class)->execute($categoryId, ['name' => ' 営業部 ']);

        $names = array_fill_keys(Locale::query()->orderBy('code')->pluck('code')->all(), '営業部');
        $this->assertSame([$names, 'folder'], $this->display("global-nav-main-cat-{$categoryId}"));
        $this->assertSame([$names, 'folder'], $this->display("sitemap-main-cat-{$categoryId}"));
    }

    public function test_a_module_with_the_longest_code_fits_in_a_category(): void
    {
        $code = 'a'.str_repeat('b', 49);
        app(CreateRecordModule::class)->execute(['code' => $code, 'name' => '長いコード']);
        $categoryId = $this->createCategory('営業');

        $this->update($code, ['categories' => [$categoryId]]);

        $this->assertSame(97, strlen("global-nav-main-cat-{$categoryId}-{$code}"));
        $this->assertSame(["global-nav-main-cat-{$categoryId}-{$code}", "sitemap-main-cat-{$categoryId}-{$code}"], $this->placements($code));
    }

    /**
     * @return array<string, array{list<mixed>}>
     */
    public static function nonPermutationIds(): array
    {
        return [
            '足りない' => [['first']],
            '重なり' => [['first', 'first', 'second']],
            '無い分類 ID' => [['first', 'second', '01arz3ndektsv4rrffq69g5fav']],
            '形の誤り' => [['first', 'Second']],
            '文字列でない' => [['first', 1]],
        ];
    }

    /**
     * @param  list<mixed>  $ids  first・second は作った分類 ID に置き換える
     */
    #[DataProvider('nonPermutationIds')]
    public function test_ids_that_are_not_a_permutation_are_rejected_and_nothing_changes(array $ids): void
    {
        $created = ['first' => $this->createCategory('営業'), 'second' => $this->createCategory('総務')];
        $before = $this->categoryOrder();

        $errors = $this->validationErrors(fn () => app(ReorderModuleCategories::class)->execute(array_map(fn (mixed $id) => is_string($id) ? ($created[$id] ?? $id) : $id, $ids)));

        $this->assertSame(['ids' => 'モジュール分類の並びが今のモジュール分類と一致しません。画面を再読み込みしてから再度操作してください'], $errors);
        $this->assertSame($before, $this->categoryOrder());
    }

    public function test_the_modules_in_a_category_are_created_in_order_and_reordered_in_both_trees(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['categories' => [$categoryId]]);
        $this->update('project_issue', ['categories' => [$categoryId]]);

        foreach (WorkMenuGroup::cases() as $group) {
            $this->assertSame([$group->categoryItemCode($categoryId, 'project') => 100, $group->categoryItemCode($categoryId, 'project_issue') => 110], $this->children($group->categoryFolderCode($categoryId)));
        }

        app(ReorderRecordModuleItems::class)->execute($categoryId, ['project_issue', 'project']);

        foreach (WorkMenuGroup::cases() as $group) {
            $this->assertSame([$group->categoryItemCode($categoryId, 'project_issue') => 100, $group->categoryItemCode($categoryId, 'project') => 110], $this->children($group->categoryFolderCode($categoryId)));
        }

        $before = $this->children("global-nav-main-cat-{$categoryId}");
        $errors = $this->validationErrors(fn () => app(ReorderRecordModuleItems::class)->execute($categoryId, ['project_issue']));
        $this->assertSame(['codes' => '業務モジュールの並びが今の並びと一致しません。画面を再読み込みしてから再度操作してください'], $errors);
        $this->assertSame($before, $this->children("global-nav-main-cat-{$categoryId}"));
    }

    public function test_reordering_the_direct_modules_keeps_the_initial_items_and_the_folders(): void
    {
        $categoryId = $this->createCategory('営業');

        app(ReorderRecordModuleItems::class)->execute(null, ['project_issue', 'meeting', 'project']);

        foreach (WorkMenuGroup::cases() as $group) {
            $this->assertSame([
                "{$group->value}-menu-home" => 0, "{$group->value}-master-organization-chart" => 1, "{$group->value}-menu-sitemap" => 2,
                "{$group->value}-cat-{$categoryId}" => 10,
                "{$group->value}-records-project_issue" => 100, "{$group->value}-records-meeting" => 110,
                "{$group->value}-records-project" => 120,
            ], $this->children($group->value));
        }
    }

    /**
     * @param  list<string>  $codes
     */
    #[TestWith([['project']])]
    #[TestWith([['project_issue', 'project', 'meeting', 'project']])]
    #[TestWith([['project_issue', 'project', 'meeting', 'project_milestone']])]
    public function test_direct_codes_that_are_not_a_permutation_are_rejected_and_nothing_changes(array $codes): void
    {
        $before = $this->children('global-nav-main');

        $errors = $this->validationErrors(fn () => app(ReorderRecordModuleItems::class)->execute(null, $codes));

        $this->assertSame(['codes' => '業務モジュールの並びが今の並びと一致しません。画面を再読み込みしてから再度操作してください'], $errors);
        $this->assertSame($before, $this->children('global-nav-main'));
    }

    public function test_the_code_of_a_hidden_module_is_rejected_in_the_direct_codes(): void
    {
        $this->update('project', ['is_visible' => false]);

        $codes = ['project_issue', 'project', 'meeting'];

        $errors = $this->validationErrors(fn () => app(ReorderRecordModuleItems::class)->execute(null, $codes));

        $this->assertSame(['codes' => '業務モジュールの並びが今の並びと一致しません。画面を再読み込みしてから再度操作してください'], $errors);
        app(ReorderRecordModuleItems::class)->execute(null, ['meeting', 'project_issue']);
        $this->assertSame(
            ['global-nav-main-records-meeting' => 100, 'global-nav-main-records-project_issue' => 110],
            array_filter($this->children('global-nav-main'), fn (int $sortNumber) => $sortNumber >= 100),
        );
    }

    public function test_a_missing_category_is_not_found_for_rename_delete_and_reorder_and_nothing_changes(): void
    {
        $this->createCategory('営業');
        $before = $this->authorizationAndMenus();

        foreach (['01arz3ndektsv4rrffq69g5fav', 'not-an-id'] as $categoryId) {
            foreach ([
                fn () => app(RenameModuleCategory::class)->execute($categoryId, ['name' => '']),
                fn () => app(DeleteModuleCategory::class)->execute($categoryId),
                fn () => app(ReorderRecordModuleItems::class)->execute($categoryId, ['nothing']),
            ] as $action) {
                try {
                    $action();
                    $this->fail('ModelNotFoundException にならなかった');
                } catch (ModelNotFoundException) {
                    $this->assertSame($before, $this->authorizationAndMenus());
                }
            }
        }
    }
}
