<?php

namespace Tests\Feature\Actions\Records;

use App\Enums\Menu\MenuType;
use App\Enums\Records\RecordModuleVisibilityChange;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuTranslation;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsRecordModuleMenus;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールを変える業務処理(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節、画面と検査.md 13 節の「業務モジュールを変える」、
 * 認可とメニュー/README.md 1 節、メニューと初期データ.md 5.1・8 節の「業務モジュールを変える業務処理」の名前とアイコンの分): 送ったキーだけが変わる。名前の変更は認可のリソースグループと
 * 「業務」の 2 つのアイテムの表示名(すべてのロケール)に、アイコンの変更はアイテムの icon_32 に追従する。明細のモジュールは名前・説明だけを変えられ、
 * 認可とメニューに触れない。規則に反する入力(is_visible を含む)と下位の業務処理の拒否は 422 で、何も書かれない。表示の切替は {@see UpdateRecordModuleVisibilityTest}。
 * 初期データのプロジェクト(親なし)とマイルストーン(明細)を使う。
 */
final class UpdateRecordModuleTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsRecordModuleMenus;

    /**
     * @return array<string, array{string, array<string, mixed>, array<string, string>}>
     */
    public static function rejectedInputs(): array
    {
        return [
            '名前の空白だけ' => ['project', ['name' => '  '], ['name' => '名前を入力してください']],
            '名前の null' => ['project', ['name' => null], ['name' => '名前を入力してください']],
            '名前の 1001 文字' => ['project', ['name' => str_repeat('あ', 1001)], ['name' => '名前は 1000 文字以内で入力してください']],
            '名前の U+0000' => ['project', ['name' => "台\0帳"], ['name' => '名前に使用できない文字(U+0000)が含まれています']],
            '説明の 4001 文字' => ['project', ['description' => str_repeat('あ', 4001)], ['description' => '説明は 4000 文字以内で入力してください']],
            '一覧に無いアイコン' => ['project', ['icon' => 'unknown'], ['icon' => 'アイコンは一覧から選択してください']],
            '許可表にあるが選べないアイコン' => ['project', ['icon' => 'house'], ['icon' => 'アイコンは一覧から選択してください']],
            '明細のモジュールのアイコン' => ['project_milestone', ['name' => '節目', 'icon' => 'table'], ['icon' => '明細のモジュールにはアイコンを設定できません']],
            '明細のモジュールの is_visible' => ['project_milestone', ['name' => '節目', 'is_visible' => true], ['is_visible' => '明細のモジュールには表示の状態を設定できません']],
            '真偽でない is_visible の文字列' => ['project', ['is_visible' => 'false'], ['is_visible' => '真偽(true・false)で指定してください']],
            '真偽でない is_visible の数' => ['project', ['is_visible' => 0], ['is_visible' => '真偽(true・false)で指定してください']],
            'ほかの誤りと一緒' => ['project', ['name' => '', 'description' => str_repeat('あ', 4001), 'icon' => 'unknown'], [
                'name' => '名前を入力してください',
                'description' => '説明は 4000 文字以内で入力してください',
                'icon' => 'アイコンは一覧から選択してください',
            ]],
        ];
    }

    public function test_changes_only_the_given_keys(): void
    {
        $this->update('project', ['description' => ' 社内の案件 ']);
        $this->assertSame(['プロジェクト', '社内の案件', 'folder-kanban'], $this->moduleValues('project'));

        $this->update('project', ['icon' => 'truck']);
        $this->assertSame(['プロジェクト', '社内の案件', 'truck'], $this->moduleValues('project'));

        $this->update('project', ['name' => ' 案件 ']);
        $this->assertSame(['案件', '社内の案件', 'truck'], $this->moduleValues('project'));
    }

    public function test_a_null_description_clears_it_and_a_null_icon_and_a_null_visibility_keep_them(): void
    {
        $this->update('project', ['description' => '説明']);
        $items = $this->itemRows('project');

        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $this->update('project', ['description' => null, 'icon' => null, 'is_visible' => null]));
        $this->assertSame(['プロジェクト', null, 'folder-kanban'], $this->moduleValues('project'));
        $this->assertSame($items, $this->itemRows('project'));

        $this->update('project', ['description' => '説明']);
        $this->update('project', ['description' => '   ']);
        $this->assertNull($this->moduleValues('project')[1]);
    }

    public function test_renaming_follows_on_the_resource_group_and_both_menu_items_in_every_locale(): void
    {
        $this->update('project', ['name' => '案件台帳']);

        $this->assertSame(['en' => '案件台帳', 'ja' => '案件台帳'], $this->resourceGroupNames('project'));
        $this->assertSame([
            'global-nav-main-records-project' => [['en' => '案件台帳', 'ja' => '案件台帳'], 'folder-kanban'],
            'sitemap-main-records-project' => [['en' => '案件台帳', 'ja' => '案件台帳'], 'folder-kanban'],
        ], $this->items('project'));
    }

    public function test_changing_the_icon_follows_on_the_icon_of_both_menu_items(): void
    {
        $this->update('project', ['icon' => 'circle-alert']);

        $this->assertSame([
            'global-nav-main-records-project' => [['en' => 'プロジェクト', 'ja' => 'プロジェクト'], 'circle-alert'],
            'sitemap-main-records-project' => [['en' => 'プロジェクト', 'ja' => 'プロジェクト'], 'circle-alert'],
        ], $this->items('project'));
    }

    public function test_renaming_creates_a_missing_locale_row_but_changing_only_the_description_does_not(): void
    {
        $this->deleteEnglishNames('project');

        $this->update('project', ['description' => '説明だけ']);
        $this->assertSame(['ja' => 'プロジェクト'], $this->resourceGroupNames('project'));
        $this->assertSame([['ja' => 'プロジェクト'], ['ja' => 'プロジェクト']], array_column($this->items('project'), 0));

        $this->update('project', ['name' => '案件']);
        $this->assertSame(['en' => '案件', 'ja' => '案件'], $this->resourceGroupNames('project'));
        $this->assertSame([['en' => '案件', 'ja' => '案件'], ['en' => '案件', 'ja' => '案件']], array_column($this->items('project'), 0));
    }

    public function test_a_missing_menu_item_is_left_untouched(): void
    {
        Menu::query()->where('code', 'sitemap-main-records-project')->delete();

        $this->update('project', ['name' => '案件', 'icon' => 'truck']);

        $this->assertSame(['global-nav-main-records-project' => [['en' => '案件', 'ja' => '案件'], 'truck']], $this->items('project'));
    }

    public function test_a_details_module_changes_its_name_and_description_without_touching_the_authorization_and_the_menus(): void
    {
        $before = $this->authorizationAndMenus();

        $change = $this->update('project_milestone', ['name' => '節目', 'description' => '工程の区切り', 'icon' => null, 'is_visible' => null]);

        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $change);

        $this->assertSame(['節目', '工程の区切り', null], $this->moduleValues('project_milestone'));
        $this->assertSame($before, $this->authorizationAndMenus());
    }

    public function test_does_not_change_the_code_the_parent_the_fields_the_rules_or_the_records(): void
    {
        $before = $this->definitionAndRecords();
        $policies = AuthzPolicy::query()->count();

        $this->update('project', ['name' => '案件', 'description' => '説明', 'icon' => 'truck', 'code' => 'renamed', 'parent_record_module_id' => 1, 'sort_key' => 5]);
        $this->update('project_milestone', ['name' => '節目', 'code' => 'renamed_rows']);

        $this->assertSame($before, $this->definitionAndRecords());
        $this->assertSame($policies, AuthzPolicy::query()->count());
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $expected
     */
    #[DataProvider('rejectedInputs')]
    public function test_rejects_an_input_that_breaks_the_rules_and_writes_nothing(string $moduleCode, array $input, array $expected): void
    {
        $before = [$this->moduleValues($moduleCode), $this->authorizationAndMenus()];

        $this->assertSame($expected, $this->validationErrors(fn () => $this->update($moduleCode, $input)));
        $this->assertSame($before, [$this->moduleValues($moduleCode), $this->authorizationAndMenus()]);
    }

    public function test_a_rejection_by_the_menu_rolls_back_the_module_the_resource_group_and_the_other_item(): void
    {
        Menu::query()->where('code', 'sitemap-main-records-project')->update(['type' => MenuType::External->value]);
        $before = [$this->moduleValues('project'), $this->authorizationAndMenus()];

        $errors = $this->validationErrors(fn () => $this->update('project', ['name' => '案件', 'icon' => 'truck']));

        $this->assertSame(['menu' => '表示名とアイコンを変更できるのはメニューフォルダかメニューアイテムだけです: sitemap-main-records-project'], $errors);
        $this->assertSame($before, [$this->moduleValues('project'), $this->authorizationAndMenus()]);
    }

    public function test_a_missing_module_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->update('nothing', ['name' => '名前']);
    }

    /**
     * 業務モジュールのリソースグループとアイテムの en の表示名を消す(行の無いロケールを作る)。
     */
    private function deleteEnglishNames(string $moduleCode): void
    {
        AuthzResourceGroupTranslation::query()->whereRelation('locale', 'code', 'en')->whereRelation('authzResourceGroup', 'code', "record-module-{$moduleCode}")->delete();
        MenuTranslation::query()->whereRelation('locale', 'code', 'en')->whereRelation('menu', 'code', 'like', "%-records-{$moduleCode}")->delete();
    }

    /**
     * 業務モジュールのコード・親・並び、項目・規則・レコードの行。
     *
     * @return array<string, mixed>
     */
    private function definitionAndRecords(): array
    {
        return [
            'modules' => RecordModule::query()->orderBy('id')->get(['id', 'code', 'parent_record_module_id', 'sort_key'])->toArray(),
            'fields' => RecordField::query()->orderBy('id')->get()->toArray(),
            'rules' => RecordRule::query()->orderBy('id')->get()->toArray(),
            'records' => Record::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
