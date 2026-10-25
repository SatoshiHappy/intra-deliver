<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReadsModuleCategories;
use Tests\Support\TransactionalTestCase;

/**
 * モジュール分類の中の業務モジュールの書き込みの口(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.4・6 節の「追加の Dialog の口」):
 * システム管理者(u0004)が分類の中の業務モジュールを揃える(トースト)と、業務モジュールの分類をドラッグで変える(トーストなし)。表示しない
 * 業務モジュールは 422 でキー categories・codes。ほかのルートと権限の組み合わせは {@see ModuleCategoryControllerTest}。
 * 初期データ(プロジェクト・課題・会議・申請書の業務モジュール 2 つ = 直下 100・110・120・130・140)を入れ、ルートの認可を外さずに開く。
 */
final class ModuleCategoryModulesControllerTest extends TransactionalTestCase
{
    use ReadsModuleCategories;

    /** 初期データの組み込みの会議(分類に入れないので「分類なし」に表示中で残る) */
    private const MEETING = ['code' => 'meeting', 'name' => '会議', 'isVisible' => true];

    /** 初期データの申請書の業務モジュール 2 つ(作った順 = 直下 130・140。分類に入れないので「分類なし」に表示中で残る) */
    private const APPLICATIONS = [
        ['code' => 'project_plan_application', 'name' => 'プロジェクト計画の申請', 'isVisible' => true],
        ['code' => 'issue_resolution_application', 'name' => '課題の対応の申請', 'isVisible' => true],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_the_system_administrator_sets_the_modules_in_a_category_with_the_toast(): void
    {
        $this->actingAs($this->user('u0004'));
        $first = $this->createCategory('営業');
        $second = $this->createCategory('総務');
        $this->put('/records/admin/modules/project_issue', ['is_visible' => true, 'categories' => [$second]])
            ->assertStatus(303);
        $this->get('/records/admin/categories')->assertOk();

        $this->put("/records/admin/categories/{$first}/modules", ['codes' => ['project_issue', 'project']])
            ->assertStatus(303)->assertRedirect('/records/admin/categories')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'モジュール分類の業務モジュールを更新しました']);
        $this->get('/records/admin/categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('categories', [
                [
                    'id' => $first, 'name' => '営業',
                    'modules' => [
                        ['code' => 'project_issue', 'name' => '課題'], ['code' => 'project', 'name' => 'プロジェクト'],
                    ],
                ],
                ['id' => $second, 'name' => '総務', 'modules' => [['code' => 'project_issue', 'name' => '課題']]],
            ])
            ->where('uncategorized', [self::MEETING, ...self::APPLICATIONS])
        );

        // 外したものは、ほかの分類に属さなければ直下の末尾に、属せばそちらに残る
        $this->put("/records/admin/categories/{$first}/modules", ['codes' => []])->assertStatus(303);
        $this->get('/records/admin/categories')->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.modules', [])
            ->where('categories.1.modules', [['code' => 'project_issue', 'name' => '課題']])
            ->where('uncategorized', [
                self::MEETING, ...self::APPLICATIONS, ['code' => 'project', 'name' => 'プロジェクト', 'isVisible' => true],
            ])
        );
    }

    public function test_the_drag_moves_copies_adds_and_removes_the_categories_of_a_module_without_a_toast(): void
    {
        $this->actingAs($this->user('u0004'));
        $first = $this->createCategory('営業');
        $second = $this->createCategory('総務');
        $this->put('/records/admin/modules/project', ['is_visible' => true, 'categories' => [$first]])
            ->assertStatus(303);
        $this->get('/records/admin/categories')->assertOk();
        $module = [RecordModule::query()->where('code', 'project')->sole()->only(['name', 'description', 'icon'])];

        // 移動 = [B]: A から消え B の末尾に
        $this->put('/records/admin/modules/project/categories', ['categories' => [$second]])
            ->assertStatus(303)->assertRedirect('/records/admin/categories')->assertInertiaFlashMissing('toast');
        $this->assertPlacedIn('project', "cat-{$second}");

        // コピー = [B, A]: B に残り A の末尾に
        $this->put('/records/admin/modules/project/categories', ['categories' => [$second, $first]])
            ->assertStatus(303)->assertInertiaFlashMissing('toast');
        $this->assertEqualsCanonicalizing([
            "global-nav-main-cat-{$first}-project", "global-nav-main-cat-{$second}-project",
            "sitemap-main-cat-{$first}-project", "sitemap-main-cat-{$second}-project",
        ], $this->placements('project'));

        // 所属が空の業務モジュールに [B]: 直下から消え B の末尾に(プロジェクトの後)
        $this->put('/records/admin/modules/project_issue/categories', ['categories' => [$second]])->assertStatus(303);
        $this->assertPlacedIn('project_issue', "cat-{$second}");
        $this->assertSame(
            ["global-nav-main-cat-{$second}-project" => 100, "global-nav-main-cat-{$second}-project_issue" => 110],
            $this->children("global-nav-main-cat-{$second}"),
        );

        // [] = 直下の末尾に
        $this->put('/records/admin/modules/project/categories', ['categories' => []])->assertStatus(303);
        $this->assertPlacedIn('project', 'records');

        $after = [RecordModule::query()->where('code', 'project')->sole()->only(['name', 'description', 'icon'])];
        $this->assertSame($module, $after);
        $this->get('/records/admin/categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('categories', [
                ['id' => $first, 'name' => '営業', 'modules' => []],
                ['id' => $second, 'name' => '総務', 'modules' => [['code' => 'project_issue', 'name' => '課題']]],
            ])
            ->where('uncategorized', [
                self::MEETING, ...self::APPLICATIONS, ['code' => 'project', 'name' => 'プロジェクト', 'isVisible' => true],
            ])
        );
    }

    public function test_the_drag_of_a_hidden_module_into_a_category_is_rejected_and_changes_nothing(): void
    {
        $this->actingAs($this->user('u0004'));
        $categoryId = $this->createCategory('営業');
        $this->put('/records/admin/modules/project', ['is_visible' => false])->assertStatus(303);
        $before = $this->snapshot();

        $this->from('/records/admin/categories')
            ->put('/records/admin/modules/project/categories', ['categories' => [$categoryId]])
            ->assertRedirect('/records/admin/categories')
            ->assertSessionHasErrors(['categories' => 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません']);
        $this->from('/records/admin/categories')
            ->put("/records/admin/categories/{$categoryId}/modules", ['codes' => ['project']])
            ->assertRedirect('/records/admin/categories')
            ->assertSessionHasErrors(['codes' => 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません']);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * 業務モジュールのアイテムが、2 つの木の同じ置き場(分類のフォルダ cat-<分類 ID> か直下 records)にだけあること。
     */
    private function assertPlacedIn(string $moduleCode, string $place): void
    {
        $expected = ["global-nav-main-{$place}-{$moduleCode}", "sitemap-main-{$place}-{$moduleCode}"];

        $this->assertSame($expected, $this->placements($moduleCode));
    }

    /**
     * 2 つの木の「業務」の子の並びと、プロジェクトのアイテムの置き場。
     *
     * @return list<array<string, int>|list<string>>
     */
    private function snapshot(): array
    {
        return [$this->children('global-nav-main'), $this->children('sitemap-main'), $this->placements('project')];
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}
