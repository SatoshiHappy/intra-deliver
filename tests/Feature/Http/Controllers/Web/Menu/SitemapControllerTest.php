<?php

namespace Tests\Feature\Http\Controllers\Web\Menu;

use App\Models\Master\User;
use Database\Seeders\E2eMenuSeeder;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransactionalTestCase;

/**
 * サイトマップ(docs の 設計/メニュー/表示.md 3.2): ページ Menu/Sitemap の props groups に、カテゴリ im_sitemap_pc の表示判定の結果を渡す。
 * 並びはランチャーのアプリ → 管理(ワークフロー・業務・管理。E2E の環境では E2E のアプリが業務の前 = 3.3.7 のサイトマップの並び)。
 * 初期データ(ゲストへの許可)を入れ、ルートの認可を外さずに開く。
 */
final class SitemapControllerTest extends TransactionalTestCase
{
    public function test_passes_the_sitemap_groups_visible_to_the_guest(): void
    {
        $this->withoutVite();

        $response = $this->get(route('menu.sitemap'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Menu/Sitemap')
            ->where('groups', [[
                'code' => 'sitemap-workflow',
                'name' => 'ワークフロー',
                'icon' => 'send',
                'description' => '申請と承認',
                'entry' => ['href' => '/workflow', 'isExternal' => false],
                'children' => [
                    $this->item('sitemap-workflow-workflow-home', 'ホーム', 'house', '/workflow'),
                    $this->folder('sitemap-workflow-folder-applications', '申請と承認', [
                        $this->item('sitemap-workflow-workflow-flows-index', '申請する', 'send', '/workflow/flows'),
                        $this->item('sitemap-workflow-workflow-cases-index', '申請一覧', 'inbox', '/workflow/cases'),
                    ]),
                    $this->folder('sitemap-workflow-folder-forms', '申請書', [
                        $this->applicationItem('project_plan_application', 'プロジェクト計画の申請', 'file-text'),
                        $this->applicationItem('issue_resolution_application', '課題の対応の申請', 'clipboard-list'),
                    ]),
                ],
            ], [
                'code' => 'sitemap-main',
                'name' => '業務',
                'icon' => 'briefcase',
                'description' => '業務のテーブル',
                'entry' => ['href' => '/records/data/project', 'isExternal' => false],
                'children' => [
                    $this->item('sitemap-main-records-project', 'プロジェクト', 'folder-kanban', '/records/data/project'),
                    $this->item('sitemap-main-records-project_issue', '課題', 'list-todo', '/records/data/project_issue'),
                    $this->item('sitemap-main-records-meeting', '会議', 'users', '/records/data/meeting'),
                    // 申請書の業務モジュールは「業務」に出ない(初期データで非表示 = ADR 0031)
                ],
            ]])
        );
    }

    public function test_passes_the_admin_group_after_the_launcher_apps_to_a_system_administrator(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->administrator())->get(route('menu.sitemap'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('groups', fn (Collection $groups) => $groups->pluck('code')->all() === ['sitemap-workflow', 'sitemap-main', 'sitemap-admin'])
            ->where('groups.2', [
                'code' => 'sitemap-admin',
                'name' => '管理',
                'icon' => 'wrench',
                'description' => 'システムの設定',
                'entry' => ['href' => '/authz/overview', 'isExternal' => false],
                'children' => [
                    $this->item('sitemap-admin-authz-overview', '認可設定', 'shield-check', '/authz/overview'),
                    $this->item('sitemap-admin-master-organization-chart', '組織図', 'network', '/master/organization-chart'),
                    $this->item('sitemap-admin-records-admin-modules-index', '業務モジュール', 'database', '/records/admin/modules'),
                    $this->item('sitemap-admin-records-admin-categories-index', 'モジュール分類', 'folder-tree', '/records/admin/categories'),
                    $this->item('sitemap-admin-workflow-admin-cases-index', '申請操作', 'list-checks', '/workflow/admin/cases'),
                    $this->item('sitemap-admin-workflow-admin-proxies-index', '代理の管理', 'user-cog', '/workflow/admin/proxies'),
                ],
            ])
        );
    }

    /**
     * E2E 専用の初期データを入れると、E2E のアプリ(並び 800)が業務の前に並び、サイトマップの今のアプリが E2E のアプリになる
     * (E2E のアイテムがサイトマップを指す = docs の 設計/メニュー/初期データ.md 4 節)。
     */
    public function test_the_e2e_app_sorts_before_the_work_app_and_owns_the_sitemap(): void
    {
        $this->withoutVite();
        $this->seed(E2eMenuSeeder::class);

        $response = $this->actingAs($this->administrator())->get(route('menu.sitemap'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('groups', fn (Collection $groups) => $groups->pluck('code')->all() === ['sitemap-workflow', 'e2e-sitemap', 'sitemap-main', 'sitemap-admin'])
            ->where('appNavigation.launcher', fn (Collection $launcher) => $launcher->pluck('code')->all() === ['global-nav-workflow', 'e2e-global-nav', 'global-nav-main'])
            ->where('appNavigation.currentApp', 'e2e-global-nav')
            ->where('appNavigation.currentItemPath', '/menu/sitemap')
        );
    }

    private function administrator(): User
    {
        return User::query()->where('code', 'u0004')->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $code, string $name, string $icon, string $href): array
    {
        return ['code' => $code, 'type' => 'item', 'name' => $name, 'icon' => $icon, 'href' => $href, 'isExternal' => false];
    }

    /**
     * 見出しのフォルダ(アイコン folder)。
     *
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function folder(string $code, string $name, array $children): array
    {
        return ['code' => $code, 'type' => 'folder', 'name' => $name, 'icon' => 'folder', 'children' => $children];
    }

    /**
     * 申請書の業務モジュールの一覧へのアイテム(サイトマップの props の形)。
     *
     * @return array<string, mixed>
     */
    private function applicationItem(string $moduleCode, string $name, string $icon): array
    {
        $code = 'sitemap-workflow-records-data-index-'.str_replace('_', '-', $moduleCode);

        return $this->item($code, $name, $icon, "/records/data/{$moduleCode}");
    }
}
