<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\CreateModuleCategory;
use App\Actions\Records\ReorderModuleCategories;
use App\Models\Master\User;
use App\Models\Menu\Menu;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールの編集の画面と更新(docs の 設計/業務データの入れ物/画面/README.md 1.1・管理の画面.md 3.5、認可とメニュー/README.md 4 節、メニューと初期データ.md 5.1・6 節・8 節の
 * 「管理」):編集の画面の props(明細のモジュールはアイコンと表示中かが null)、更新の成功は定義の画面へ 303 とトースト(表示の変化で「業務モジュールを更新しました」
 * 「…サイドバーで非表示にしました」「…サイドバーに表示しました」)、業務処理の 422 は元の画面へ戻り入力の名前のキー(下位の業務処理の拒否は下位のキー menu など、
 * アイテムを作る・消す段の拒否は is_visible)にエラーが付く。隠しても業務の一覧は URL で開ける。入口は name・description・icon・is_visible・categories だけを渡す。
 * 編集の画面の props にはモジュール分類(所属の分類 ID と、すべての分類 = モジュール分類/画面と検査.md 4.2)が入り、categories の誤りはキー categories。
 * ゲストと u0001 は 403 で何も変わらず、業務モジュールが無ければ壊れた入力でも 422 より先に 404。初期データを入れ、今日を 2026-10-11 に固定し、ルートの認可を外さずに開く。
 */
final class RecordModuleEditControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_the_edit_form_carries_the_current_values_and_the_icon_choices(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->get('/records/admin/modules/project/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Admin/ModuleEdit')
            ->where('module', ['code' => 'project', 'name' => 'プロジェクト', 'description' => $this->module('project')->description, 'icon' => 'folder-kanban', 'isVisible' => true, 'isDetails' => false, 'categoryIds' => [], 'isAttachmentEnabled' => true, 'rowAccessScope' => 'all'])
            ->has('icons', 25)
            ->where('icons.0', ['value' => 'table', 'label' => '表'])
            ->where('categories', [])
            ->where('textMaxLengths', ['code' => 50, 'name' => 1000, 'description' => 4000])
        );
        $this->get('/records/admin/modules/project_milestone/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('module.icon', null)
            ->where('module.isVisible', null)
            ->where('module.isDetails', true)
            ->where('module.categoryIds', null)
            ->where('module.isAttachmentEnabled', null)
        );
    }

    public function test_the_edit_form_carries_the_categories_in_order_and_the_ones_the_module_belongs_to(): void
    {
        $this->actingAs($this->user('u0004'));
        $first = app(CreateModuleCategory::class)->execute(['name' => '営業']);
        $second = app(CreateModuleCategory::class)->execute(['name' => '総務']);
        app(ReorderModuleCategories::class)->execute([$second, $first]);

        $this->put('/records/admin/modules/project', ['is_visible' => true, 'categories' => [$first]])
            ->assertStatus(303)->assertRedirect('/records/admin/modules/project')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '業務モジュールを更新しました']);

        $this->get('/records/admin/modules/project/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('module.categoryIds', [$first])
            ->where('categories', [['id' => $second, 'name' => '総務'], ['id' => $first, 'name' => '営業']])
        );
        $this->assertSame(0, Menu::query()->where('code', 'like', '%-records-project')->count());
        $this->assertSame(2, Menu::query()->where('code', 'like', "%-cat-{$first}-project")->count());

        $this->put('/records/admin/modules/project', ['is_visible' => false, 'categories' => []])
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '業務モジュールを更新し、サイドバーで非表示にしました']);
        $this->get('/records/admin/modules/project/edit')->assertInertia(fn (Assert $page) => $page->where('module.isVisible', false)->where('module.categoryIds', []));
    }

    public function test_a_rejection_of_the_categories_returns_to_the_form_with_the_key_categories_and_nothing_changes(): void
    {
        $this->actingAs($this->user('u0004'));
        $categoryId = app(CreateModuleCategory::class)->execute(['name' => '営業']);

        $this->from('/records/admin/modules/project/edit')->put('/records/admin/modules/project', ['is_visible' => false, 'categories' => [$categoryId]])
            ->assertRedirect('/records/admin/modules/project/edit')
            ->assertSessionHasErrors(['categories' => 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません']);
        $this->from('/records/admin/modules/project_milestone/edit')->put('/records/admin/modules/project_milestone', ['name' => '節目', 'categories' => []])
            ->assertRedirect('/records/admin/modules/project_milestone/edit')
            ->assertSessionHasErrors(['categories' => '明細のモジュールにはモジュール分類を設定できません']);

        $this->assertSame(2, Menu::query()->where('code', 'like', '%-records-project')->count());
        $this->assertSame('マイルストーン', $this->module('project_milestone')->name);
    }

    public function test_updates_the_module_and_returns_to_its_definition_with_the_toast(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->put('/records/admin/modules/project', ['name' => ' 案件 ', 'description' => '', 'icon' => 'truck'])
            ->assertStatus(303)->assertRedirect('/records/admin/modules/project')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '業務モジュールを更新しました']);

        $this->assertSame(['案件', null, 'truck'], $this->values('project'));
        $this->assertSame(['案件', 'truck'], [$this->item('global-nav-main-records-project')->menuTranslations()->value('display_name'), $this->item('global-nav-main-records-project')->icon_32]);
    }

    public function test_a_details_module_changes_its_name_and_rejects_an_icon_with_its_key(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->put('/records/admin/modules/project_milestone', ['name' => '節目', 'description' => '工程'])
            ->assertStatus(303)->assertRedirect('/records/admin/modules/project_milestone');
        $this->assertSame(['節目', '工程', null], $this->values('project_milestone'));

        $this->from('/records/admin/modules/project_milestone/edit')->put('/records/admin/modules/project_milestone', ['name' => '節目', 'icon' => 'table'])
            ->assertRedirect('/records/admin/modules/project_milestone/edit')
            ->assertSessionHasErrors(['icon' => '明細のモジュールにはアイコンを設定できません']);
    }

    public function test_the_errors_return_to_the_form_with_the_names_of_the_input_and_nothing_changes(): void
    {
        $this->actingAs($this->user('u0004'));
        $before = $this->values('project');

        $this->from('/records/admin/modules/project/edit')->put('/records/admin/modules/project', ['name' => '  ', 'icon' => 'house'])
            ->assertRedirect('/records/admin/modules/project/edit')
            ->assertSessionHasErrors(['name' => '名前を入力してください', 'icon' => 'アイコンは一覧から選択してください']);

        $this->assertSame($before, $this->values('project'));
    }

    public function test_a_rejection_by_the_menu_returns_to_the_form_with_the_key_of_the_menu_and_nothing_changes(): void
    {
        $this->actingAs($this->user('u0004'));
        Menu::query()->where('code', 'sitemap-main-records-project')->update(['type' => 'external']);

        $this->from('/records/admin/modules/project/edit')->put('/records/admin/modules/project', ['name' => '案件'])
            ->assertRedirect('/records/admin/modules/project/edit')
            ->assertSessionHasErrors(['menu']);

        $this->assertSame('プロジェクト', $this->module('project')->name);
        $this->assertSame('プロジェクト', $this->item('global-nav-main-records-project')->menuTranslations()->whereRelation('locale', 'code', 'ja')->value('display_name'));
    }

    public function test_hiding_and_showing_choose_their_toasts_and_the_hidden_module_still_opens_by_its_url(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->put('/records/admin/modules/project', ['name' => 'プロジェクト', 'is_visible' => false])
            ->assertStatus(303)->assertRedirect('/records/admin/modules/project')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '業務モジュールを更新し、サイドバーで非表示にしました']);
        $this->assertSame(0, Menu::query()->where('code', 'like', '%-records-project')->count());
        $this->get('/records/admin/modules')->assertInertia(
            fn (Assert $page) => $page->where('modules.2.code', 'project')->where('modules.2.isVisible', false),
        );
        $this->get('/records/admin/modules/project/edit')->assertInertia(fn (Assert $page) => $page->where('module.isVisible', false));

        $this->put('/records/admin/modules/project', ['name' => 'プロジェクト', 'is_visible' => false])
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '業務モジュールを更新しました']);
        $this->put('/records/admin/modules/project', ['name' => 'プロジェクト', 'is_visible' => true])
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '業務モジュールを更新し、サイドバーに表示しました']);
        $this->assertSame(2, Menu::query()->where('code', 'like', '%-records-project')->count());
    }

    #[TestWith([null])]
    #[TestWith(['u0001'])]
    public function test_the_guest_and_a_user_open_the_list_of_a_hidden_module(?string $userCode): void
    {
        $this->actingAs($this->user('u0004'))->put('/records/admin/modules/project', ['is_visible' => false])->assertStatus(303);
        auth()->logout();

        if ($userCode !== null) {
            $this->actingAs($this->user($userCode));
        }

        $this->get('/records/data/project')->assertOk();
    }

    public function test_a_rejection_of_the_visibility_returns_to_the_form_with_the_key_is_visible(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->from('/records/admin/modules/project/edit')->put('/records/admin/modules/project', ['is_visible' => 'yes'])
            ->assertRedirect('/records/admin/modules/project/edit')
            ->assertSessionHasErrors(['is_visible' => '真偽(true・false)で指定してください']);
        $this->from('/records/admin/modules/project_milestone/edit')->put('/records/admin/modules/project_milestone', ['name' => '節目', 'is_visible' => true])
            ->assertRedirect('/records/admin/modules/project_milestone/edit')
            ->assertSessionHasErrors(['is_visible' => '明細のモジュールには表示の状態を設定できません']);

        $this->assertSame(2, Menu::query()->where('code', 'like', '%-records-project')->count());
        $this->assertSame('マイルストーン', $this->module('project_milestone')->name);
    }

    public function test_the_entrance_passes_only_the_name_the_description_the_icon_and_the_visibility(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->put('/records/admin/modules/project', ['description' => '説明', 'code' => 'renamed', 'parent_record_module_id' => 1])->assertStatus(303);

        $this->assertSame(['プロジェクト', '説明', 'folder-kanban'], $this->values('project'));
        $this->assertNull($this->module('project')->parent_record_module_id);
    }

    #[TestWith([null, 'GET', '/records/admin/modules/project/edit'])]
    #[TestWith([null, 'PUT', '/records/admin/modules/project'])]
    #[TestWith(['u0001', 'GET', '/records/admin/modules/project/edit'])]
    #[TestWith(['u0001', 'PUT', '/records/admin/modules/project'])]
    public function test_the_guest_and_a_user_without_the_system_administrator_role_are_forbidden_and_nothing_changes(?string $userCode, string $method, string $url): void
    {
        if ($userCode !== null) {
            $this->actingAs($this->user($userCode));
        }

        $this->call($method, $url, ['name' => '案件', 'icon' => 'truck'])->assertForbidden();

        $this->assertSame('プロジェクト', $this->module('project')->name);
    }

    /**
     * 業務モジュールが無ければ、壊れた入力でも入力の検査より先に 404(誤った入力は 302/422 にならないことで順序を見る)。
     *
     * @param  array<string, mixed>  $input
     */
    #[TestWith(['GET', '/records/admin/modules/nothing/edit'])]
    #[TestWith(['PUT', '/records/admin/modules/nothing', ['name' => '名前']])]
    #[TestWith(['PUT', '/records/admin/modules/nothing', ['name' => '', 'icon' => 'house']])]
    #[TestWith(['PUT', '/records/admin/modules/nothing', ['is_visible' => 'yes']])]
    public function test_a_missing_module_is_not_found(string $method, string $url, array $input = []): void
    {
        $this->actingAs($this->user('u0004'))->call($method, $url, $input)->assertNotFound();
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    private function module(string $code): RecordModule
    {
        return RecordModule::query()->where('code', $code)->sole();
    }

    /**
     * [名前, 説明, アイコン]。
     *
     * @return array{string, string|null, string|null}
     */
    private function values(string $code): array
    {
        $module = $this->module($code);

        return [$module->name, $module->description, $module->icon];
    }

    private function item(string $code): Menu
    {
        return Menu::query()->where('code', $code)->sole();
    }
}
