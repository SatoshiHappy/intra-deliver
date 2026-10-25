<?php

namespace Tests\Feature\Http\Controllers\Web\Menu;

use App\Models\Master\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransactionalTestCase;

/**
 * サイトマップ(docs の 設計/メニュー/表示.md 3.2): ページ Menu/Sitemap の props groups に、カテゴリ im_sitemap_pc の表示判定の結果を渡す。
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
                'code' => 'sitemap-main',
                'name' => '業務',
                'children' => [
                    ['code' => 'sitemap-main-menu-home', 'type' => 'item', 'name' => 'ホーム', 'icon' => 'house', 'href' => '/', 'isExternal' => false],
                    ['code' => 'sitemap-main-master-organization-chart', 'type' => 'item', 'name' => '組織図', 'icon' => 'network', 'href' => '/master/organization-chart', 'isExternal' => false],
                    ['code' => 'sitemap-main-menu-sitemap', 'type' => 'item', 'name' => 'サイトマップ', 'icon' => 'map', 'href' => '/menu/sitemap', 'isExternal' => false],
                    ['code' => 'sitemap-main-records-project', 'type' => 'item', 'name' => 'プロジェクト', 'icon' => 'folder-kanban', 'href' => '/records/data/project', 'isExternal' => false],
                    ['code' => 'sitemap-main-records-project_issue', 'type' => 'item', 'name' => '課題', 'icon' => 'list-todo', 'href' => '/records/data/project_issue', 'isExternal' => false],
                    [
                        'code' => 'sitemap-main-records-meeting', 'type' => 'item', 'name' => '会議', 'icon' => 'users',
                        'href' => '/records/data/meeting', 'isExternal' => false,
                    ],
                    $this->applicationItem(
                        'sitemap-main-records-project_plan_application',
                        'project_plan_application', 'プロジェクト計画の申請', 'file-text',
                    ),
                    $this->applicationItem(
                        'sitemap-main-records-issue_resolution_application',
                        'issue_resolution_application', '課題の対応の申請', 'clipboard-list',
                    ),
                ],
            ], [
                'code' => 'sitemap-workflow',
                'name' => 'ワークフロー',
                'children' => [
                    ['code' => 'sitemap-workflow-workflow-flows-index', 'type' => 'item', 'name' => '申請する', 'icon' => 'send', 'href' => '/workflow/flows', 'isExternal' => false],
                    ['code' => 'sitemap-workflow-workflow-cases-index', 'type' => 'item', 'name' => '申請一覧', 'icon' => 'inbox', 'href' => '/workflow/cases', 'isExternal' => false],
                    $this->applicationItem(
                        'sitemap-workflow-records-data-index-project-plan-application',
                        'project_plan_application', 'プロジェクト計画の申請', 'file-text',
                    ),
                    $this->applicationItem(
                        'sitemap-workflow-records-data-index-issue-resolution-application',
                        'issue_resolution_application', '課題の対応の申請', 'clipboard-list',
                    ),
                ],
            ]])
        );
    }

    public function test_passes_the_admin_group_to_a_system_administrator(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::query()->where('code', 'u0004')->sole())->get(route('menu.sitemap'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('groups', 3)
            ->where('groups.0.code', 'sitemap-main')
            ->where('groups.1.code', 'sitemap-workflow')
            ->where('groups.2', [
                'code' => 'sitemap-admin',
                'name' => '管理',
                'children' => [
                    ['code' => 'sitemap-admin-authz-overview', 'type' => 'item', 'name' => '認可設定', 'icon' => 'shield-check', 'href' => '/authz/overview', 'isExternal' => false],
                    ['code' => 'sitemap-admin-records-admin-modules-index', 'type' => 'item', 'name' => '業務モジュール', 'icon' => 'database', 'href' => '/records/admin/modules', 'isExternal' => false],
                    ['code' => 'sitemap-admin-records-admin-categories-index', 'type' => 'item', 'name' => 'モジュール分類', 'icon' => 'folder-tree', 'href' => '/records/admin/categories', 'isExternal' => false],
                ],
            ])
        );
    }

    /**
     * 申請書の業務モジュールの一覧へのアイテム(サイトマップの props の形)。
     *
     * @return array<string, mixed>
     */
    private function applicationItem(string $code, string $moduleCode, string $name, string $icon): array
    {
        return [
            'code' => $code, 'type' => 'item', 'name' => $name, 'icon' => $icon,
            'href' => "/records/data/{$moduleCode}", 'isExternal' => false,
        ];
    }
}
