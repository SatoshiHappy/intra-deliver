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
 * ワークフローのルートの認可とメニュー(docs の 設計/ワークフロー/認可と一覧.md 4 節・6 節・7 節の HTTP の Feature テスト): 27 のルートのルートの認可が
 * ゲストと認証済みに execute を許す(AuthorizationQuery::allowsUri で確かめる = FormRequest の 403 と取り違えない)、このモジュールのポリシーが 54 件
 * (ルートの深さ/認可と一覧.md 4.1 = 処理対象者の候補の 2 件と、設計/ワークフロー/案件の運用/認可と一覧.md の 4 節 = 一括承認・一括確認・利用者の候補・
 * 保留・保留解除・振替・確認の 14 件と、設計/ワークフロー/申請の周辺/初期データ.md の 3 節 = 写しの値の入口の 2 件を足した)、
 * ゲストと u0001 のサイドバーとサイトマップに「ワークフロー」の 4 アイテム(申請する・申請一覧・申請書の業務モジュール 2 つ。並びは業務の後・管理の前)。初期データを入れ、ルートの認可を外さない。
 */
final class WorkflowRouteAuthorizationTest extends TransactionalTestCase
{
    /**
     * ワークフローのルート 27 本(設計/ワークフロー/画面/README.md 1.1 の表の順と、ルートの深さ/画面.md 1.1 の処理対象者の候補、
     * 案件の運用/画面/README.md の 1.1 節の 7 本、写しの値の入口)
     */
    private const ROUTES = [
        'workflow.flows.index', 'workflow.applications.create', 'workflow.applications.store', 'workflow.drafts.store', 'workflow.drafts.edit',
        'workflow.drafts.update', 'workflow.drafts.apply', 'workflow.drafts.destroy', 'workflow.cases.index', 'workflow.cases.show',
        'workflow.cases.approve', 'workflow.cases.approve-early', 'workflow.cases.reject', 'workflow.cases.send-back', 'workflow.cases.pull-back',
        'workflow.cases.withdraw', 'workflow.reapplications.create', 'workflow.reapplications.store', 'workflow.cases.assignee-candidates',
        'workflow.cases.bulk-approve', 'workflow.cases.bulk-confirm', 'workflow.user-candidates',
        'workflow.cases.hold', 'workflow.cases.release', 'workflow.cases.transfer', 'workflow.cases.confirm',
        'workflow.target-values',
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

        $this->assertEqualsCanonicalizing(self::ROUTES, $appRouteNames);

        foreach (self::ROUTES as $name) {
            $this->assertTrue($authorization->allowsUri(new Requester($userId, null), $routes->uriOf($name), 'execute', CarbonImmutable::today('Asia/Tokyo')), $name);
        }
    }

    public function test_the_module_has_54_policies_permitting_execute_to_the_guest_and_the_authenticated(): void
    {
        $summaries = AuthzPolicy::query()->with('authzResourceGroup')->get()
            ->filter(fn (AuthzPolicy $policy) => str_starts_with((string) $policy->authzResourceGroup?->code, 'http-services.workflow'))
            ->map(fn (AuthzPolicy $policy) => "{$policy->authzResourceGroup?->code} {$policy->resource_type}:{$policy->action} {$policy->effect->value}")
            ->all();
        $expected = array_merge(...array_map(fn (string $name) => array_fill(0, 2, "http-services.{$name} service:execute permit"), self::ROUTES));

        $this->assertCount(54, $summaries);
        $this->assertEqualsCanonicalizing($expected, array_values($summaries));
    }

    #[DataProvider('requesters')]
    public function test_the_sidebar_and_the_sitemap_show_the_workflow_group_between_work_and_administration(?string $userCode): void
    {
        if ($userCode !== null) {
            $this->actingAs(User::query()->where('code', $userCode)->sole());
        }

        $item = fn (string $code, string $name, string $icon, string $href) => [
            'code' => $code, 'type' => 'item', 'name' => $name, 'icon' => $icon, 'href' => $href, 'isExternal' => false,
        ];
        // 申請書の業務モジュール 2 つのアイテムが続く(docs の 設計/ワークフロー/認可と一覧.md の 6 節)
        $workflow = fn (string $prefix) => [
            'code' => "{$prefix}-workflow",
            'name' => 'ワークフロー',
            'children' => [
                $item("{$prefix}-workflow-workflow-flows-index", '申請する', 'send', '/workflow/flows'),
                $item("{$prefix}-workflow-workflow-cases-index", '申請一覧', 'inbox', '/workflow/cases'),
                $item(
                    "{$prefix}-workflow-records-data-index-project-plan-application", 'プロジェクト計画の申請', 'file-text',
                    '/records/data/project_plan_application',
                ),
                $item(
                    "{$prefix}-workflow-records-data-index-issue-resolution-application", '課題の対応の申請', 'clipboard-list',
                    '/records/data/issue_resolution_application',
                ),
            ],
        ];

        $this->get(route('menu.sitemap'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('globalNavigation.0.code', 'global-nav-main')
            ->where('globalNavigation.1', $workflow('global-nav'))
            ->where('groups.0.code', 'sitemap-main')
            ->where('groups.1', $workflow('sitemap'))
        );
    }
}
