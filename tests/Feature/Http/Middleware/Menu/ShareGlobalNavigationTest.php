<?php

namespace Tests\Feature\Http\Middleware\Menu;

use App\Models\Master\User;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * グローバルナビの共有(docs の 設計/メニュー/表示.md 3.2): 全ページの共有プロパティ globalNavigation に、カテゴリ im_global_nav_pc の
 * 表示判定の結果を渡す。部分再読み込み(only で別の props だけを求める要求)でも毎回判定して返す(表示.md 3.1 の 8)。
 * 初期データ(ゲストへの許可。グループ「管理」はシステム管理者のロールだけに見える)を入れ、ルートの認可を外さずに開く。
 */
final class ShareGlobalNavigationTest extends TransactionalTestCase
{
    #[TestWith(['/', 'Menu/Home'])]
    #[TestWith(['/menu/sitemap', 'Menu/Sitemap'])]
    public function test_shares_the_global_navigation_of_the_guest_with_every_page(string $path, string $component): void
    {
        $this->withoutVite();

        $response = $this->get($path);

        $response->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('globalNavigation', $this->globalNavigationOfTheGuest())
        );
    }

    public function test_shares_the_global_navigation_also_on_a_partial_reload_of_other_props(): void
    {
        $this->withoutVite();

        $response = $this->get('/menu/sitemap');

        $response->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('groups', fn (Assert $reload) => $reload->where('globalNavigation', $this->globalNavigationOfTheGuest()))
        );
    }

    public function test_shares_the_admin_group_with_a_system_administrator(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::query()->where('code', 'u0004')->sole())->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('globalNavigation', [...$this->globalNavigationOfTheGuest(), [
                'code' => 'global-nav-admin',
                'name' => '管理',
                'children' => [
                    ['code' => 'global-nav-admin-authz-overview', 'type' => 'item', 'name' => '認可設定', 'icon' => 'shield-check', 'href' => '/authz/overview', 'isExternal' => false],
                    ['code' => 'global-nav-admin-records-admin-modules-index', 'type' => 'item', 'name' => '業務モジュール', 'icon' => 'database', 'href' => '/records/admin/modules', 'isExternal' => false],
                    ['code' => 'global-nav-admin-records-admin-categories-index', 'type' => 'item', 'name' => 'モジュール分類', 'icon' => 'folder-tree', 'href' => '/records/admin/categories', 'isExternal' => false],
                ],
            ]])
        );
    }

    /**
     * 初期データのゲストのグローバルナビ(業務・ワークフロー。ワークフローは並び 50 = 業務の後・管理の前)。
     * 申請書の業務モジュール 2 つは、「業務」の末尾とワークフローの末尾の両方に出る。
     *
     * @return list<array<string, mixed>>
     */
    private function globalNavigationOfTheGuest(): array
    {
        return [[
            'code' => 'global-nav-main',
            'name' => '業務',
            'children' => [
                ['code' => 'global-nav-main-menu-home', 'type' => 'item', 'name' => 'ホーム', 'icon' => 'house', 'href' => '/', 'isExternal' => false],
                ['code' => 'global-nav-main-master-organization-chart', 'type' => 'item', 'name' => '組織図', 'icon' => 'network', 'href' => '/master/organization-chart', 'isExternal' => false],
                ['code' => 'global-nav-main-menu-sitemap', 'type' => 'item', 'name' => 'サイトマップ', 'icon' => 'map', 'href' => '/menu/sitemap', 'isExternal' => false],
                ['code' => 'global-nav-main-records-project', 'type' => 'item', 'name' => 'プロジェクト', 'icon' => 'folder-kanban', 'href' => '/records/data/project', 'isExternal' => false],
                ['code' => 'global-nav-main-records-project_issue', 'type' => 'item', 'name' => '課題', 'icon' => 'list-todo', 'href' => '/records/data/project_issue', 'isExternal' => false],
                [
                    'code' => 'global-nav-main-records-meeting', 'type' => 'item', 'name' => '会議', 'icon' => 'users',
                    'href' => '/records/data/meeting', 'isExternal' => false,
                ],
                [
                    'code' => 'global-nav-main-records-project_plan_application', 'type' => 'item',
                    'name' => 'プロジェクト計画の申請', 'icon' => 'file-text',
                    'href' => '/records/data/project_plan_application', 'isExternal' => false,
                ],
                [
                    'code' => 'global-nav-main-records-issue_resolution_application', 'type' => 'item',
                    'name' => '課題の対応の申請', 'icon' => 'clipboard-list',
                    'href' => '/records/data/issue_resolution_application', 'isExternal' => false,
                ],
            ],
        ], [
            'code' => 'global-nav-workflow',
            'name' => 'ワークフロー',
            'children' => [
                ['code' => 'global-nav-workflow-workflow-flows-index', 'type' => 'item', 'name' => '申請する', 'icon' => 'send', 'href' => '/workflow/flows', 'isExternal' => false],
                ['code' => 'global-nav-workflow-workflow-cases-index', 'type' => 'item', 'name' => '申請一覧', 'icon' => 'inbox', 'href' => '/workflow/cases', 'isExternal' => false],
                [
                    'code' => 'global-nav-workflow-records-data-index-project-plan-application', 'type' => 'item',
                    'name' => 'プロジェクト計画の申請', 'icon' => 'file-text',
                    'href' => '/records/data/project_plan_application', 'isExternal' => false,
                ],
                [
                    'code' => 'global-nav-workflow-records-data-index-issue-resolution-application', 'type' => 'item',
                    'name' => '課題の対応の申請', 'icon' => 'clipboard-list',
                    'href' => '/records/data/issue_resolution_application', 'isExternal' => false,
                ],
            ],
        ]];
    }
}
