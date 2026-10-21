<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\UpdateRecordModule;
use App\Models\Master\User;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\ReadsModuleCategories;
use Tests\Support\TransactionalTestCase;

/**
 * モジュール分類の画面と書き込みの口(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.3・4.4・6 節の「権限の組み合わせ」の入口の分、
 * 画面/README.md 1.1、認可とメニュー/README.md 4 節、メニューと初期データ.md 6 節): ゲストと u0001 は 9 つのルートが 403 で何も変わらない。システム管理者(u0004)は書き込み →
 * 移り先の records.admin.categories.index が 200 で props に結果が出る(作成・名前・削除・分類の中の業務モジュールの更新はトースト、並び替えと
 * 業務モジュールの分類の更新(ドラッグ)はトーストなし)。無い分類は壊れた入力でも
 * 422 より先に 404(無い業務モジュールのコードも同じ)。入れ物の形の誤りと業務処理の 422 は元の画面へ戻り、入力の名前のキーにエラーが付く。ルート reorder は {module}・{category} に取られない。
 * 初期データ(プロジェクト・課題 = 直下 100・110)を入れ、ルートの認可を外さずに開く。
 */
final class ModuleCategoryControllerTest extends TransactionalTestCase
{
    use ReadsModuleCategories;

    /** 初期データの組み込みの会議(分類に入れないので「分類なし」に表示中で残る) */
    private const MEETING = ['code' => 'meeting', 'name' => '会議', 'isVisible' => true];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    /**
     * ゲストと u0001 は 9 つのルートが 403 で、分類も並びも変わらない(書き込みの後の移り先の GET も 403)。{id} は作った分類 ID に置き換える。
     *
     * @param  array<string, mixed>  $input
     */
    #[TestWith([null, 'GET', '/records/admin/categories'])]
    #[TestWith([null, 'POST', '/records/admin/categories', ['name' => '総務']])]
    #[TestWith([null, 'PUT', '/records/admin/categories/{id}', ['name' => '総務']])]
    #[TestWith([null, 'DELETE', '/records/admin/categories/{id}'])]
    #[TestWith([null, 'PUT', '/records/admin/categories/reorder', ['ids' => ['{id}']]])]
    #[TestWith([null, 'PUT', '/records/admin/categories/{id}/reorder', ['codes' => []]])]
    #[TestWith([null, 'PUT', '/records/admin/modules/reorder', ['codes' => ['project_issue', 'project']]])]
    #[TestWith([null, 'PUT', '/records/admin/categories/{id}/modules', ['codes' => ['project']]])]
    #[TestWith([null, 'PUT', '/records/admin/modules/project/categories', ['categories' => ['{id}']]])]
    #[TestWith(['u0001', 'GET', '/records/admin/categories'])]
    #[TestWith(['u0001', 'POST', '/records/admin/categories', ['name' => '総務']])]
    #[TestWith(['u0001', 'PUT', '/records/admin/categories/{id}', ['name' => '総務']])]
    #[TestWith(['u0001', 'DELETE', '/records/admin/categories/{id}'])]
    #[TestWith(['u0001', 'PUT', '/records/admin/categories/reorder', ['ids' => ['{id}']]])]
    #[TestWith(['u0001', 'PUT', '/records/admin/categories/{id}/reorder', ['codes' => []]])]
    #[TestWith(['u0001', 'PUT', '/records/admin/modules/reorder', ['codes' => ['project_issue', 'project']]])]
    #[TestWith(['u0001', 'PUT', '/records/admin/categories/{id}/modules', ['codes' => ['project']]])]
    #[TestWith(['u0001', 'PUT', '/records/admin/modules/project/categories', ['categories' => ['{id}']]])]
    public function test_the_guest_and_a_user_without_the_system_administrator_role_are_forbidden_and_nothing_changes(?string $userCode, string $method, string $url, array $input = []): void
    {
        $categoryId = $this->createCategory('営業');
        $before = [$this->children('global-nav-main'), $this->children('sitemap-main'), $this->display("global-nav-main-cat-{$categoryId}")];

        if ($userCode !== null) {
            $this->actingAs($this->user($userCode));
        }

        $replace = fn (mixed $value): mixed => is_string($value) ? str_replace('{id}', $categoryId, $value) : $value;
        $this->call($method, $replace($url), array_map(fn (mixed $value) => is_array($value) ? array_map($replace, $value) : $replace($value), $input))->assertForbidden();
        $this->get('/records/admin/categories')->assertForbidden();

        $this->assertSame($before, [$this->children('global-nav-main'), $this->children('sitemap-main'), $this->display("global-nav-main-cat-{$categoryId}")]);
    }

    public function test_the_page_carries_the_categories_with_their_modules_and_the_uncategorized_modules_with_the_hidden_last(): void
    {
        $this->actingAs($this->user('u0004'));
        $first = $this->createCategory('営業');
        $second = $this->createCategory('総務');
        $this->put('/records/admin/modules/project_issue', ['is_visible' => true, 'categories' => [$first, $second]])->assertStatus(303);
        app(UpdateRecordModule::class)->execute('project', ['is_visible' => false]);

        $this->get('/records/admin/categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Admin/ModuleCategories')
            ->where('categories', [
                ['id' => $first, 'name' => '営業', 'modules' => [['code' => 'project_issue', 'name' => '課題']]],
                ['id' => $second, 'name' => '総務', 'modules' => [['code' => 'project_issue', 'name' => '課題']]],
            ])
            ->where('uncategorized', [self::MEETING, ['code' => 'project', 'name' => 'プロジェクト', 'isVisible' => false]])
            ->where('textMaxLengths', ['code' => 50, 'name' => 1000, 'description' => 4000])
        );
        // 明細のモジュール(マイルストーン)は出ない。表示中の直下は「業務」の直下の並び、続けて非表示
        $this->put('/records/admin/modules/project_issue', ['is_visible' => true, 'categories' => []])->assertStatus(303);
        $this->get('/records/admin/categories')->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.modules', [])
            ->where('uncategorized', [
                self::MEETING,
                ['code' => 'project_issue', 'name' => '課題', 'isVisible' => true],
                ['code' => 'project', 'name' => 'プロジェクト', 'isVisible' => false],
            ])
        );
    }

    public function test_the_system_administrator_creates_renames_and_deletes_a_category_with_the_toasts(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->post('/records/admin/categories', ['name' => ' 営業 '])
            ->assertStatus(303)->assertRedirect('/records/admin/categories')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'モジュール分類を作成しました']);
        $categoryId = array_key_first($this->categoryOrder()['global-nav-main']);
        $this->assertIsString($categoryId);
        $this->get('/records/admin/categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('categories', [['id' => $categoryId, 'name' => '営業', 'modules' => []]])
        );

        $this->put("/records/admin/categories/{$categoryId}", ['name' => '営業部'])
            ->assertStatus(303)->assertRedirect('/records/admin/categories')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'モジュール分類を更新しました']);
        $this->get('/records/admin/categories')->assertInertia(fn (Assert $page) => $page->where('categories.0.name', '営業部'));

        $this->put('/records/admin/modules/project', ['is_visible' => true, 'categories' => [$categoryId]])->assertStatus(303);
        $this->delete("/records/admin/categories/{$categoryId}")
            ->assertStatus(303)->assertRedirect('/records/admin/categories')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'モジュール分類を削除しました']);
        // ほかの分類に属さないプロジェクトは「業務」の直下の末尾へ
        $this->get('/records/admin/categories')->assertInertia(fn (Assert $page) => $page
            ->where('categories', [])
            ->where('uncategorized.2', ['code' => 'project', 'name' => 'プロジェクト', 'isVisible' => true])
        );
    }

    public function test_the_system_administrator_reorders_the_categories_the_modules_in_a_category_and_the_direct_modules_without_a_toast(): void
    {
        $this->actingAs($this->user('u0004'));
        $first = $this->createCategory('営業');
        $second = $this->createCategory('総務');
        $this->put('/records/admin/modules/project', ['is_visible' => true, 'categories' => [$first]])->assertStatus(303);
        $this->put('/records/admin/modules/project_issue', ['is_visible' => true, 'categories' => [$first]])->assertStatus(303);
        // 業務モジュールの更新のトーストを次の画面で受け取っておく(並び替えの応答にトーストが無いことを見るため)
        $this->get('/records/admin/categories')->assertOk();

        $this->put('/records/admin/categories/reorder', ['ids' => [$second, $first]])
            ->assertStatus(303)->assertRedirect('/records/admin/categories')->assertInertiaFlashMissing('toast');
        $this->put("/records/admin/categories/{$first}/reorder", ['codes' => ['project_issue', 'project']])
            ->assertStatus(303)->assertRedirect('/records/admin/categories')->assertInertiaFlashMissing('toast');

        $this->get('/records/admin/categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.id', $second)
            ->where('categories.1.modules', [['code' => 'project_issue', 'name' => '課題'], ['code' => 'project', 'name' => 'プロジェクト']])
        );

        $this->put('/records/admin/modules/project', ['is_visible' => true, 'categories' => []])->assertStatus(303);
        $this->put('/records/admin/modules/project_issue', ['is_visible' => true, 'categories' => []])->assertStatus(303);
        $this->get('/records/admin/categories')->assertOk();
        $this->put('/records/admin/modules/reorder', ['codes' => ['project_issue', 'project', 'meeting']])
            ->assertStatus(303)->assertRedirect('/records/admin/categories')->assertInertiaFlashMissing('toast');

        $this->get('/records/admin/categories')->assertInertia(fn (Assert $page) => $page
            ->where('uncategorized.0.code', 'project_issue')
            ->where('uncategorized.1.code', 'project')
        );
        $this->assertSame([
            'global-nav-main-records-project_issue' => 100, 'global-nav-main-records-project' => 110,
            'global-nav-main-records-meeting' => 120,
        ], array_filter(
            $this->children('global-nav-main'),
            fn (string $code) => str_starts_with($code, 'global-nav-main-records-'),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * 入れ物の形の誤りと業務処理の 422 は元の画面へ戻り、入力の名前のキーにエラーが付き、何も変わらない。
     *
     * @param  array<string, mixed>  $input
     */
    #[TestWith(['POST', '/records/admin/categories', ['name' => ''], 'name', '名前を入力してください'])]
    #[TestWith(['PUT', '/records/admin/categories/{id}', ['name' => ' '], 'name', '名前を入力してください'])]
    #[TestWith(['PUT', '/records/admin/categories/reorder', ['ids' => 'x'], 'ids', 'モジュール分類の並びの形が正しくありません'])]
    #[TestWith(['PUT', '/records/admin/categories/reorder', ['ids' => ['{id}', '{id}']], 'ids', 'モジュール分類の並びが今のモジュール分類と一致しません。画面を再読み込みしてから再度操作してください'])]
    #[TestWith(['PUT', '/records/admin/categories/{id}/reorder', ['codes' => 'x'], 'codes', '業務モジュールの並びの形が正しくありません'])]
    #[TestWith(['PUT', '/records/admin/categories/{id}/reorder', ['codes' => ['project']], 'codes', '業務モジュールの並びが今の並びと一致しません。画面を再読み込みしてから再度操作してください'])]
    #[TestWith(['PUT', '/records/admin/modules/reorder', ['codes' => ['project']], 'codes', '業務モジュールの並びが今の並びと一致しません。画面を再読み込みしてから再度操作してください'])]
    #[TestWith(['PUT', '/records/admin/categories/{id}/modules', ['codes' => 'x'], 'codes', '業務モジュールの一覧の形が正しくありません'])]
    #[TestWith(['PUT', '/records/admin/categories/{id}/modules', [], 'codes', '業務モジュールの一覧の形が正しくありません'])]
    #[TestWith(['PUT', '/records/admin/categories/{id}/modules', ['codes' => ['project', 'project']], 'codes', '同じ業務モジュールが重複しています'])]
    #[TestWith(['PUT', '/records/admin/categories/{id}/modules', ['codes' => ['project_milestone']], 'codes', '明細のモジュールにはモジュール分類を設定できません'])]
    #[TestWith(['PUT', '/records/admin/modules/project/categories', ['categories' => 'x'], 'categories', 'モジュール分類の一覧の形が正しくありません'])]
    #[TestWith(['PUT', '/records/admin/modules/project/categories', [], 'categories', 'モジュール分類の一覧の形が正しくありません'])]
    #[TestWith(['PUT', '/records/admin/modules/project/categories', ['categories' => [1]], 'categories', 'モジュール分類は分類 ID の配列で指定してください'])]
    #[TestWith(['PUT', '/records/admin/modules/project/categories', ['categories' => ['{id}', '{id}']], 'categories', '同じモジュール分類が重複しています'])]
    #[TestWith(['PUT', '/records/admin/modules/project/categories', ['categories' => ['01jz0000000000000000000000']], 'categories', 'モジュール分類が見つかりません。画面を再読み込みしてから再度操作してください'])]
    #[TestWith(['PUT', '/records/admin/modules/project_milestone/categories', ['categories' => []], 'categories', '明細のモジュールにはモジュール分類を設定できません'])]
    public function test_a_rejection_returns_to_the_page_with_the_key_of_the_input_and_nothing_changes(string $method, string $url, array $input, string $key, string $message): void
    {
        $this->actingAs($this->user('u0004'));
        $categoryId = $this->createCategory('営業');
        $before = [$this->children('global-nav-main'), $this->children('sitemap-main'), $this->display("global-nav-main-cat-{$categoryId}")];
        $replace = fn (mixed $value): mixed => is_string($value) ? str_replace('{id}', $categoryId, $value) : $value;

        $this->from('/records/admin/categories')
            ->call($method, $replace($url), array_map(fn (mixed $value) => is_array($value) ? array_map($replace, $value) : $replace($value), $input))
            ->assertRedirect('/records/admin/categories')
            ->assertSessionHasErrors([$key => $message]);

        $this->assertSame($before, [$this->children('global-nav-main'), $this->children('sitemap-main'), $this->display("global-nav-main-cat-{$categoryId}")]);
    }

    /**
     * 無い分類(形に合う分類 ID)と無い業務モジュールのコードは、壊れた入力でも入力の検査より先に 404。形に合わない分類 ID はどのルートにも合わず 404。
     *
     * @param  array<string, mixed>  $input
     */
    #[TestWith(['PUT', '/records/admin/categories/01jz0000000000000000000000', ['name' => '']])]
    #[TestWith(['DELETE', '/records/admin/categories/01jz0000000000000000000000'])]
    #[TestWith(['PUT', '/records/admin/categories/01jz0000000000000000000000/reorder', ['codes' => 'x']])]
    #[TestWith(['PUT', '/records/admin/categories/01jz0000000000000000000000/modules', ['codes' => 'x']])]
    #[TestWith(['PUT', '/records/admin/modules/example_nothing/categories', ['categories' => 'x']])]
    #[TestWith(['PUT', '/records/admin/categories/nothing', ['name' => '総務']])]
    public function test_a_missing_category_is_not_found_before_the_input_is_checked(string $method, string $url, array $input = []): void
    {
        $this->actingAs($this->user('u0004'))->call($method, $url, $input)->assertNotFound();
    }

    public function test_the_system_administrator_sets_the_modules_in_a_category_with_the_toast(): void
    {
        $this->actingAs($this->user('u0004'));
        $first = $this->createCategory('営業');
        $second = $this->createCategory('総務');
        $this->put('/records/admin/modules/project_issue', ['is_visible' => true, 'categories' => [$second]])->assertStatus(303);
        $this->get('/records/admin/categories')->assertOk();

        $this->put("/records/admin/categories/{$first}/modules", ['codes' => ['project_issue', 'project']])
            ->assertStatus(303)->assertRedirect('/records/admin/categories')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'モジュール分類の業務モジュールを更新しました']);
        $this->get('/records/admin/categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('categories', [
                ['id' => $first, 'name' => '営業', 'modules' => [['code' => 'project_issue', 'name' => '課題'], ['code' => 'project', 'name' => 'プロジェクト']]],
                ['id' => $second, 'name' => '総務', 'modules' => [['code' => 'project_issue', 'name' => '課題']]],
            ])
            ->where('uncategorized', [self::MEETING])
        );

        // 外したものは、ほかの分類に属さなければ直下の末尾に、属せばそちらに残る
        $this->put("/records/admin/categories/{$first}/modules", ['codes' => []])->assertStatus(303);
        $this->get('/records/admin/categories')->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.modules', [])
            ->where('categories.1.modules', [['code' => 'project_issue', 'name' => '課題']])
            ->where('uncategorized', [self::MEETING, ['code' => 'project', 'name' => 'プロジェクト', 'isVisible' => true]])
        );
    }

    public function test_the_drag_moves_copies_adds_and_removes_the_categories_of_a_module_without_a_toast(): void
    {
        $this->actingAs($this->user('u0004'));
        $first = $this->createCategory('営業');
        $second = $this->createCategory('総務');
        $this->put('/records/admin/modules/project', ['is_visible' => true, 'categories' => [$first]])->assertStatus(303);
        $this->get('/records/admin/categories')->assertOk();
        $module = [RecordModule::query()->where('code', 'project')->sole()->only(['name', 'description', 'icon'])];

        // 移動 = [B]: A から消え B の末尾に
        $this->put('/records/admin/modules/project/categories', ['categories' => [$second]])
            ->assertStatus(303)->assertRedirect('/records/admin/categories')->assertInertiaFlashMissing('toast');
        $this->assertSame(["global-nav-main-cat-{$second}-project", "sitemap-main-cat-{$second}-project"], $this->placements('project'));

        // コピー = [B, A]: B に残り A の末尾に
        $this->put('/records/admin/modules/project/categories', ['categories' => [$second, $first]])->assertStatus(303)->assertInertiaFlashMissing('toast');
        $this->assertEqualsCanonicalizing([
            "global-nav-main-cat-{$first}-project", "global-nav-main-cat-{$second}-project", "sitemap-main-cat-{$first}-project", "sitemap-main-cat-{$second}-project",
        ], $this->placements('project'));

        // 所属が空の業務モジュールに [B]: 直下から消え B の末尾に(プロジェクトの後)
        $this->put('/records/admin/modules/project_issue/categories', ['categories' => [$second]])->assertStatus(303);
        $this->assertSame(["global-nav-main-cat-{$second}-project_issue", "sitemap-main-cat-{$second}-project_issue"], $this->placements('project_issue'));
        $this->assertSame(["global-nav-main-cat-{$second}-project" => 100, "global-nav-main-cat-{$second}-project_issue" => 110], $this->children("global-nav-main-cat-{$second}"));

        // [] = 直下の末尾に
        $this->put('/records/admin/modules/project/categories', ['categories' => []])->assertStatus(303);
        $this->assertSame(['global-nav-main-records-project', 'sitemap-main-records-project'], $this->placements('project'));

        $this->assertSame($module, [RecordModule::query()->where('code', 'project')->sole()->only(['name', 'description', 'icon'])]);
        $this->get('/records/admin/categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('categories', [
                ['id' => $first, 'name' => '営業', 'modules' => []],
                ['id' => $second, 'name' => '総務', 'modules' => [['code' => 'project_issue', 'name' => '課題']]],
            ])
            ->where('uncategorized', [self::MEETING, ['code' => 'project', 'name' => 'プロジェクト', 'isVisible' => true]])
        );
    }

    public function test_the_drag_of_a_hidden_module_into_a_category_is_rejected_with_the_key_categories_and_nothing_changes(): void
    {
        $this->actingAs($this->user('u0004'));
        $categoryId = $this->createCategory('営業');
        $this->put('/records/admin/modules/project', ['is_visible' => false])->assertStatus(303);
        $before = [$this->children('global-nav-main'), $this->children('sitemap-main'), $this->placements('project')];

        $this->from('/records/admin/categories')->put('/records/admin/modules/project/categories', ['categories' => [$categoryId]])
            ->assertRedirect('/records/admin/categories')
            ->assertSessionHasErrors(['categories' => 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません']);
        $this->from('/records/admin/categories')->put("/records/admin/categories/{$categoryId}/modules", ['codes' => ['project']])
            ->assertRedirect('/records/admin/categories')
            ->assertSessionHasErrors(['codes' => 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません']);

        $this->assertSame($before, [$this->children('global-nav-main'), $this->children('sitemap-main'), $this->placements('project')]);
    }

    #[TestWith(['/records/admin/modules/reorder', 'records.admin.modules.reorder'])]
    #[TestWith(['/records/admin/categories/reorder', 'records.admin.categories.reorder'])]
    public function test_the_reorder_routes_are_not_taken_by_the_routes_with_an_argument(string $url, string $name): void
    {
        $this->assertSame($name, Route::getRoutes()->match(Request::create($url, 'PUT'))->getName());
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}
