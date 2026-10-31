<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Authz\AuthzPolicy;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\RouteResources;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローのルートの認可とメニュー(docs の 設計/ワークフロー/認可と一覧.md 4 節・6 節・7 節の HTTP の Feature テスト): 32 のルートのルートの認可が
 * ゲストと認証済みに execute を許す(AuthorizationQuery::allowsUri で確かめる = FormRequest の 403 と取り違えない)、申請操作の 6 本と代理の管理の 3 本と
 * 定義の管理の 8 本は許さない、
 * このモジュールのポリシーが 64 件(ワークフローのホームの 2 件と、ルートの深さ/認可と一覧.md 4.1 = 処理対象者の候補の 2 件と、設計/ワークフロー/案件の運用/認可と一覧.md の 4 節 =
 * 一括承認・一括確認・利用者の候補・保留・保留解除・振替・確認・代理の設定の 20 件と、設計/ワークフロー/申請の周辺/初期データ.md の 3 節 = 写しの値の入口の 2 件を足した)、
 * ゲストと u0001 のランチャーとサイトマップの「ワークフロー」の 5 アイテム(ホーム・申請する・申請一覧・申請書の業務モジュール 2 つ。並びは先頭)。
 * 初期データを入れ、ルートの認可を外さない。
 */
final class WorkflowRouteAuthorizationTest extends TransactionalTestCase
{
    /**
     * ゲストと認証済みに許すワークフローのルート 32 本(ワークフローのホーム = 設計/ワークフロー/認可と一覧.md 4 節、設計/ワークフロー/画面/README.md 1.1 の表の順と、
     * ルートの深さ/画面/README.md 1.1 の処理対象者の候補、案件の運用/画面/README.md の 1.1 節の 11 本、写しの値の入口)
     */
    private const ROUTES = [
        'workflow.home', 'workflow.flows.index', 'workflow.applications.create', 'workflow.applications.store', 'workflow.drafts.store', 'workflow.drafts.edit',
        'workflow.drafts.update', 'workflow.drafts.apply', 'workflow.drafts.destroy', 'workflow.cases.index', 'workflow.cases.show',
        'workflow.cases.approve', 'workflow.cases.approve-early', 'workflow.cases.reject', 'workflow.cases.send-back', 'workflow.cases.pull-back',
        'workflow.cases.withdraw', 'workflow.reapplications.create', 'workflow.reapplications.store', 'workflow.cases.assignee-candidates',
        'workflow.cases.bulk-approve', 'workflow.cases.bulk-confirm', 'workflow.user-candidates',
        'workflow.cases.hold', 'workflow.cases.release', 'workflow.cases.transfer', 'workflow.cases.confirm',
        'workflow.target-values', 'workflow.record-candidates',
        'workflow.proxies.index', 'workflow.proxies.store', 'workflow.proxies.destroy',
    ];

    /** システム管理者だけのルート(ポリシーなし = 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節、定義の管理 = ルートの深さ/認可と一覧.md 4.2 節) */
    private const ADMIN_ROUTES = [
        'workflow.admin.cases.index', 'workflow.admin.cases.retarget', 'workflow.admin.cases.reexpand',
        'workflow.admin.cases.move', 'workflow.admin.cases.assign', 'workflow.admin.cases.destroy',
        'workflow.admin.proxies.index', 'workflow.admin.proxies.store', 'workflow.admin.proxies.destroy',
        'workflow.admin.flows.index', 'workflow.admin.flows.show', 'workflow.admin.flows.referrers.update',
        'workflow.admin.versions.store', 'workflow.admin.versions.disable', 'workflow.admin.versions.enable',
        'workflow.admin.nodes.update', 'workflow.admin.subject-candidates',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function requesters(): array
    {
        return ['guest' => [null], 'authenticated u0001' => ['u0001']];
    }

    #[DataProvider('requesters')]
    public function test_the_route_authorization_permits_every_workflow_route(?string $userCode): void
    {
        $userId = $userCode === null ? null : User::query()->where('code', $userCode)->sole()->id;
        $routes = app(RouteResources::class);
        $authorization = app(AuthorizationQuery::class);
        $appRouteNames = array_values(array_filter(array_map(fn ($route) => $route->getName(), $routes->appRoutes()), fn (?string $name) => str_starts_with((string) $name, 'workflow.')));

        $this->assertEqualsCanonicalizing([...self::ROUTES, ...self::ADMIN_ROUTES], $appRouteNames);

        $requester = new Requester($userId, null);
        $today = CarbonImmutable::today('Asia/Tokyo');

        foreach ([...self::ROUTES, ...self::ADMIN_ROUTES] as $name) {
            $allowed = $authorization->allowsUri($requester, $routes->uriOf($name), 'execute', $today);
            $this->assertSame(in_array($name, self::ROUTES, true), $allowed, $name);
        }
    }

    public function test_the_module_has_64_policies_permitting_execute_to_the_guest_and_the_authenticated(): void
    {
        $summaries = AuthzPolicy::query()->with('authzResourceGroup')->get()
            ->filter(fn (AuthzPolicy $policy) => str_starts_with((string) $policy->authzResourceGroup?->code, 'http-services.workflow'))
            ->map(fn (AuthzPolicy $policy) => "{$policy->authzResourceGroup?->code} {$policy->resource_type}:{$policy->action} {$policy->effect->value}")
            ->all();
        $expected = array_merge(...array_map(fn (string $name) => array_fill(0, 2, "http-services.{$name} service:execute permit"), self::ROUTES));

        $this->assertCount(64, $summaries);
        $this->assertEqualsCanonicalizing($expected, array_values($summaries));
    }

    #[DataProvider('requesters')]
    public function test_the_launcher_and_the_sitemap_show_the_workflow_app_first(?string $userCode): void
    {
        if ($userCode !== null) {
            $this->actingAs(User::query()->where('code', $userCode)->sole());
        }

        $item = fn (string $code, string $name, string $icon, string $href) => [
            'code' => $code, 'type' => 'item', 'name' => $name, 'icon' => $icon, 'href' => $href, 'isExternal' => false,
        ];
        $folder = fn (string $code, string $name, array $children) => [
            'code' => $code, 'type' => 'folder', 'name' => $name, 'icon' => 'folder', 'children' => $children,
        ];
        // 申請書の業務モジュール 2 つのアイテムは見出し「申請書」の中(docs の 設計/ワークフロー/認可と一覧.md の 6 節、設計/メニュー/初期データ.md 4 節)
        $workflow = fn (string $prefix) => [
            'code' => "{$prefix}-workflow",
            'name' => 'ワークフロー',
            'icon' => 'send',
            'description' => '申請と承認',
            'entry' => ['href' => '/workflow', 'isExternal' => false],
            'children' => [
                $item("{$prefix}-workflow-workflow-home", 'ホーム', 'house', '/workflow'),
                $folder("{$prefix}-workflow-folder-applications", '申請と承認', [
                    $item("{$prefix}-workflow-workflow-flows-index", '申請する', 'send', '/workflow/flows'),
                    $item("{$prefix}-workflow-workflow-cases-index", '申請一覧', 'inbox', '/workflow/cases'),
                ]),
                $folder("{$prefix}-workflow-folder-forms", '申請書', [
                    $item(
                        "{$prefix}-workflow-records-data-index-project-plan-application", 'プロジェクト計画の申請', 'file-text',
                        '/records/data/project_plan_application',
                    ),
                    $item(
                        "{$prefix}-workflow-records-data-index-issue-resolution-application", '課題の対応の申請', 'clipboard-list',
                        '/records/data/issue_resolution_application',
                    ),
                ]),
            ],
        ];

        $this->get(route('menu.sitemap'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('appNavigation.launcher.0', $workflow('global-nav'))
            ->where('appNavigation.launcher.1.code', 'global-nav-main')
            ->where('groups.0', $workflow('sitemap'))
            ->where('groups.1.code', 'sitemap-main')
            // 「管理」(申請操作・代理の管理を含む)はシステム管理者だけ(ShareAppNavigationTest・SitemapControllerTest)
            ->count('appNavigation.launcher', 2)
            ->count('appNavigation.adminMenu', 0)
            ->count('groups', 2)
        );
    }
}
