<?php

namespace Tests\Feature\Http\Middleware\Menu;

use App\Models\Authz\AuthzPolicy;
use App\Models\Master\User;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 骨格の共有(docs の 設計/メニュー/表示.md 3.2 の「置き場」・3.3): 全ページの共有プロパティ appNavigation に、ランチャーのアプリ
 * (im_global_nav_pc)・歯車のアプリ(admin_menu_pc)の表示判定の結果と、今のアプリ・現在地のパス・サイトマップを出すかを渡す。部分再読み込み
 * (only で別の props だけを求める要求)でも毎回判定して返す(表示.md 3.1 の 8)。初期データを入れ、ルートの認可を外さずに開く。
 * 今のアプリの規則は CurrentAppTest(テストで置いたアプリ)と CurrentAppOnSeededDataTest(初期データ)。
 */
final class ShareAppNavigationTest extends TransactionalTestCase
{
    #[TestWith(['/', 'Menu/Home'])]
    #[TestWith(['/menu/sitemap', 'Menu/Sitemap'])]
    public function test_shares_the_app_navigation_of_the_guest_with_every_page(string $path, string $component): void
    {
        $this->withoutVite();

        $response = $this->get($path);

        $response->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('appNavigation', [
                'launcher' => $this->launcherOfTheGuest(),
                'adminMenu' => [],
                'currentApp' => null,
                'currentItemPath' => null,
                'showSitemap' => true,
            ])
        );
    }

    public function test_shares_the_app_navigation_also_on_a_partial_reload_of_other_props(): void
    {
        $this->withoutVite();

        $response = $this->get('/menu/sitemap');

        $response->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('groups', fn (Assert $reload) => $reload
                ->where('appNavigation.launcher', $this->launcherOfTheGuest()))
        );
    }

    /**
     * ワークフローのホームを開いた要求では、今のアプリがワークフローで、現在地はホーム。
     */
    public function test_shares_the_current_app_and_the_current_item_path_of_the_screen(): void
    {
        $this->withoutVite();

        $this->get('/workflow')->assertInertia(fn (Assert $page) => $page
            ->where('appNavigation.currentApp', 'global-nav-workflow')
            ->where('appNavigation.currentItemPath', '/workflow')
        );
    }

    public function test_shares_the_admin_app_in_the_admin_menu_with_a_system_administrator(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::query()->where('code', 'u0004')->sole())->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('appNavigation.adminMenu', [[
                'code' => 'global-nav-admin',
                'name' => '管理',
                'icon' => 'wrench',
                'description' => 'システムの設定',
                'entry' => ['href' => '/authz/overview', 'isExternal' => false],
                'children' => $this->items('global-nav-admin', [
                    ['authz-overview', '認可設定', 'shield-check', '/authz/overview'],
                    ['master-organization-chart', '組織図', 'network', '/master/organization-chart'],
                    ['records-admin-modules-index', '業務モジュール', 'database', '/records/admin/modules'],
                    ['records-admin-categories-index', 'モジュール分類', 'folder-tree', '/records/admin/categories'],
                    ['workflow-admin-cases-index', '申請操作', 'list-checks', '/workflow/admin/cases'],
                    ['workflow-admin-proxies-index', '代理の管理', 'user-cog', '/workflow/admin/proxies'],
                ]),
            ]])
        );
    }

    /**
     * ランチャーのアプリのグループの read をすべて外した人には、ランチャーのアプリが無い(launcher が空)。サイトマップのルートの execute を
     * 外すと showSitemap が偽。
     */
    public function test_without_the_reads_of_the_launcher_apps_the_launcher_is_empty(): void
    {
        $this->withoutVite();
        AuthzPolicy::query()->whereHas('authzResourceGroup', fn ($query) => $query->whereIn('code', [
            'im-menu-group-global-nav-workflow', 'im-menu-group-global-nav-main', 'http-services.menu.sitemap',
        ]))->delete();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('appNavigation.launcher', [])
            ->where('appNavigation.adminMenu', [])
            ->where('appNavigation.showSitemap', false)
        );
    }

    /**
     * 初期データのゲストのランチャーのアプリ(ワークフロー 50 → 業務 900)。ワークフローは、見出しなしのホームの後に見出しのフォルダ
     * 「申請と承認」「申請書」。申請書の業務モジュール 2 つは、ワークフローの「申請書」にだけ出る(「業務」には出ない = ADR 0031)。
     *
     * @return list<array<string, mixed>>
     */
    private function launcherOfTheGuest(): array
    {
        $workflow = 'global-nav-workflow';
        $applications = $this->items($workflow, [
            ['workflow-flows-index', '申請する', 'send', '/workflow/flows'],
            ['workflow-cases-index', '申請一覧', 'inbox', '/workflow/cases'],
        ]);
        $forms = $this->items($workflow, [
            [
                'records-data-index-project-plan-application', 'プロジェクト計画の申請', 'file-text',
                '/records/data/project_plan_application',
            ],
            [
                'records-data-index-issue-resolution-application', '課題の対応の申請', 'clipboard-list',
                '/records/data/issue_resolution_application',
            ],
        ]);

        return [[
            'code' => $workflow,
            'name' => 'ワークフロー',
            'icon' => 'send',
            'description' => '申請と承認',
            'entry' => ['href' => '/workflow', 'isExternal' => false],
            'children' => [
                ...$this->items($workflow, [['workflow-home', 'ホーム', 'house', '/workflow']]),
                $this->folder("{$workflow}-folder-applications", '申請と承認', $applications),
                $this->folder("{$workflow}-folder-forms", '申請書', $forms),
            ],
        ], [
            'code' => 'global-nav-main',
            'name' => '業務',
            'icon' => 'briefcase',
            'description' => '業務のテーブル',
            'entry' => ['href' => '/records/data/project', 'isExternal' => false],
            'children' => $this->items('global-nav-main', [
                ['records-project', 'プロジェクト', 'folder-kanban', '/records/data/project'],
                ['records-project_issue', '課題', 'list-todo', '/records/data/project_issue'],
                ['records-meeting', '会議', 'users', '/records/data/meeting'],
            ]),
        ]];
    }

    /**
     * アプリの中のパスのアイテム(メニュー ID = <グループ ID>-<接尾辞>)。
     *
     * @param  list<array{string, string, string, string}>  $rows  [メニュー ID の接尾辞, 表示名, アイコン, href]
     * @return list<array<string, mixed>>
     */
    private function items(string $groupCode, array $rows): array
    {
        return array_map(fn (array $row) => [
            'code' => "{$groupCode}-{$row[0]}", 'type' => 'item', 'name' => $row[1], 'icon' => $row[2],
            'href' => $row[3], 'isExternal' => false,
        ], $rows);
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
}
