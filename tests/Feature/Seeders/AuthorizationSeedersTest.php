<?php

namespace Tests\Feature\Seeders;

use App\Enums\Menu\MenuType;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectCategory;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuGroup;
use App\Models\Menu\MenuGroupCategory;
use App\Models\Menu\MenuGroupCategoryInclusion;
use App\Models\Menu\MenuGroupCategoryTranslation;
use App\Models\Menu\MenuTranslation;
use App\Queries\Authz\ResourceTypeCatalog;
use App\Queries\Authz\RouteResources;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FindsAppRouteViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可・メニュー・今の利用者の初期データ(docs の 設計/メニュー/初期データ.md 4 節、設計/デモユーザー切替/今の利用者.md 5.8)と、初期データを入れた後の付け忘れ・登録もれの検査
 * (認可との結び付け.md 2.3 の検査 2・3、初期データ.md の全部を許すロールの検査)。検査 2 は、ルートのリソースのリソースグループ ID と親も、
 * 名前から決まるもの(2.3 の表)であることを確かめる(判定は {@see FindsAppRouteViolations})。
 * メニューのアイテムとポリシーの件数は、ワークフローの申請書の業務モジュール 2 つ(設計/ワークフロー/初期データ.md の 3 節)の分を含む:
 * 業務モジュールを作る業務処理が足すアイテム 2 × 2 と初期ポリシー 8 × 2、グループ「ワークフロー」に MenuSeeder が足すアイテム 2 × 2
 * (設計/ワークフロー/認可と一覧.md の 6 節)と、グループ「管理」の「申請操作」「代理の管理」2 × 2(設計/ワークフロー/案件の運用/認可と一覧.md の 5 節)。
 */
final class AuthorizationSeedersTest extends TransactionalTestCase
{
    use FindsAppRouteViolations;

    public function test_every_app_route_has_its_service_resource_in_the_resource_groups_given_by_its_name(): void
    {
        $this->assertSame([], $this->routesWithoutTheirResource($this->routeResources()->appRoutes()));
    }

    public function test_every_menu_item_points_to_an_external_site_or_an_app_route_with_its_resource(): void
    {
        $routeResources = app(RouteResources::class);

        $invalid = Menu::query()->where('type', MenuType::Item)->get()->reject(function (Menu $item) use ($routeResources) {
            if (str_starts_with((string) $item->url, 'http://') || str_starts_with((string) $item->url, 'https://')) {
                return true;
            }

            $route = $routeResources->match($item->method->value, (string) $item->url);

            return $route?->getName() !== null && AuthzResource::query()->where('uri', $routeResources->uriOf($route->getName()))->exists();
        });

        $this->assertSame([], $invalid->pluck('code')->all());
        $this->assertSame(26 + 4 + 4 + 4, Menu::query()->where('type', MenuType::Item)->count());
    }

    public function test_the_system_administrator_is_permitted_every_action_of_the_types_in_each_set(): void
    {
        $administrator = $this->subjectGroupWithRole('system_admin');

        $permitted = AuthzPolicy::query()->with('authzResourceGroup')
            ->where(['authz_subject_group_id' => $administrator->id, 'effect' => 'permit'])
            ->get()
            ->map(fn (AuthzPolicy $policy) => "{$policy->authzResourceGroup?->code} {$policy->resource_type}:{$policy->action}")
            ->all();

        $this->assertSame([], array_values(array_diff($this->everyActionOfEachSet(), $permitted)));
        $this->assertSame(['http-services', 'im-menu-group', 'record-modules', 'workflow-flows'], AuthzResourceGroup::query()->whereNull('parent_id')->orderBy('code')->pluck('code')->all());
    }

    public function test_seeds_the_subjects_categories_and_policies_of_the_design(): void
    {
        // imm_* = ワークフローの処理対象者と参照者の条件(ユーザー・組織・役職)で、サブジェクトタイプが新しいときだけ足すカテゴリ(設計/共通マスタ/ロールと認可/対象者.md)
        $this->assertSame(['b_m_role', 'im_authz_meta_subject', 'imm_company_post', 'imm_department', 'imm_user'], AuthzSubjectCategory::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(['home_quick_actions_pc', 'im_global_nav_pc', 'im_global_nav_sp', 'im_personal_settings_pc', 'im_site_help_pc', 'im_sitemap_pc', 'im_sitemap_sp'], MenuGroupCategory::query()->orderBy('code')->pluck('code')->all());
        $this->assertEqualsCanonicalizing([
            'http-services service:execute permit システム管理者',
            'im-menu-group im-menu-group:read permit システム管理者',
            'im-menu-group im-menu-group:admin permit システム管理者',
            'im-menu-group-global-nav-main im-menu-group:read permit ゲストユーザ',
            'im-menu-group-global-nav-main im-menu-group:read permit 認証済みユーザ',
            'im-menu-group-sitemap-main im-menu-group:read permit ゲストユーザ',
            'im-menu-group-sitemap-main im-menu-group:read permit 認証済みユーザ',
            'im-menu-group-global-nav-workflow im-menu-group:read permit ゲストユーザ',
            'im-menu-group-global-nav-workflow im-menu-group:read permit 認証済みユーザ',
            'im-menu-group-sitemap-workflow im-menu-group:read permit ゲストユーザ',
            'im-menu-group-sitemap-workflow im-menu-group:read permit 認証済みユーザ',
            'im-menu-group-home-quick-actions im-menu-group:read permit 認証済みユーザ',
            'http-services.menu.home service:execute permit ゲストユーザ',
            'http-services.menu.home service:execute permit 認証済みユーザ',
            'http-services.menu.sitemap service:execute permit ゲストユーザ',
            'http-services.menu.sitemap service:execute permit 認証済みユーザ',
            'http-services.master.organization-chart service:execute permit ゲストユーザ',
            'http-services.master.organization-chart service:execute permit 認証済みユーザ',
            'http-services.master.search service:execute permit ゲストユーザ',
            'http-services.master.search service:execute permit 認証済みユーザ',
            'http-services.account.logout service:execute permit ゲストユーザ',
            'http-services.account.logout service:execute permit 認証済みユーザ',
            'http-services.account.current-department service:execute permit 認証済みユーザ',
            ...$this->recordsPolicySummaries(),
            ...$this->workflowPolicySummaries(),
            ...$this->permits('workflow-flows', ['workflow-flow:read', 'workflow-flow:operate'], ['システム管理者']),
            ...$this->everyonePolicySummaries('notice', 'index', 'read', 'read-all', 'latest'),
            ...$this->everyonePolicySummaries('meeting', 'store', 'regenerate'),
        ], $this->policySummaries());
    }

    public function test_seeds_the_display_names_and_the_selectability_of_the_categories_of_the_design(): void
    {
        $categories = MenuGroupCategory::query()->with('menuGroupCategoryTranslations.locale')->orderBy('code')->get()
            ->map(fn (MenuGroupCategory $category) => [
                $category->code,
                $category->menuGroupCategoryTranslations->sortBy(fn (MenuGroupCategoryTranslation $translation) => $translation->locale?->code)
                    ->map(fn (MenuGroupCategoryTranslation $translation) => "{$translation->locale?->code} {$translation->display_name}")->values()->all(),
                $category->is_selectable,
            ])
            ->all();

        $this->assertSame([
            ['home_quick_actions_pc', ['en Home quick actions (PC)', 'ja ホームの主な操作（PC用）'], true],
            ['im_global_nav_pc', ['en Global navigation (PC)', 'ja グローバルナビ（PC用）'], true],
            ['im_global_nav_sp', ['en Global navigation (smartphone)', 'ja グローバルナビ（スマートフォン用）'], true],
            ['im_personal_settings_pc', ['en Personal settings (PC)', 'ja 個人設定（PC用）'], true],
            ['im_site_help_pc', ['en Help (PC)', 'ja ヘルプ（PC用）'], true],
            ['im_sitemap_pc', ['en Sitemap (PC)', 'ja サイトマップ（PC用）'], true],
            ['im_sitemap_sp', ['en Sitemap (smartphone)', 'ja サイトマップ（スマートフォン用）'], true],
        ], $categories);
    }

    public function test_seeds_the_menu_groups_of_the_design(): void
    {
        $groups = MenuGroup::query()->with(['menu.menuTranslations.locale', 'menuGroupCategoryInclusions.menuGroupCategory'])->get()
            ->map(fn (MenuGroup $group) => [
                $group->menu?->code,
                $group->menuGroupCategoryInclusions->map(fn (MenuGroupCategoryInclusion $inclusion) => $inclusion->menuGroupCategory?->code)->all(),
                $group->menu?->sort_number,
                $group->menu?->icon_32,
                $group->menu?->menuTranslations->sortBy(fn (MenuTranslation $translation) => $translation->locale?->code)
                    ->map(fn (MenuTranslation $translation) => "{$translation->locale?->code} {$translation->display_name}")->values()->all(),
            ])
            ->sortBy(fn (array $group) => $group[0])
            ->values()
            ->all();

        $this->assertSame([
            ['global-nav-admin', ['im_global_nav_pc'], 100, null, ['en Administration', 'ja 管理']],
            ['global-nav-main', ['im_global_nav_pc'], 0, null, ['en Work', 'ja 業務']],
            ['global-nav-workflow', ['im_global_nav_pc'], 50, null, ['en Workflow', 'ja ワークフロー']],
            ['home-quick-actions', ['home_quick_actions_pc'], 0, null, ['en Quick actions', 'ja 主な操作']],
            ['sitemap-admin', ['im_sitemap_pc'], 100, null, ['en Administration', 'ja 管理']],
            ['sitemap-main', ['im_sitemap_pc'], 0, null, ['en Work', 'ja 業務']],
            ['sitemap-workflow', ['im_sitemap_pc'], 50, null, ['en Workflow', 'ja ワークフロー']],
        ], $groups);
    }

    /**
     * 後の seeder は対象者を条件で引き直し、同じ対象者を作り足さない(FindsAuthzSubjects)。対象者 = ポリシーの 3 と、ワークフローの処理対象者・参照者・確認する人の 6(用途 selection。
     * ルート・フローの業務処理が作る = 設計/ワークフロー/初期データ.md 2 節)の計 9。ポリシー = 162(56 + メニューのワークフローのグループ 4 + ホームの主な操作のグループ 1 + ワークフローのルート
     * 62 = ルートの深さ/初期データ.md 6 節と案件の運用の 11 本の 22・写しの値の入口 2。通知のルート 8・フローのセット 2 = 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節・3 節。
     * 業務モジュールの全件の参照 1 = 設計/業務データの入れ物/認可とメニュー/README.md の 3 節。添付と外した時点のファイルのルート 6 × 2 = 12、コメントのルート 3 × 2 = 6 =
     * 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 2.3 節。組み込みの会議の業務モジュール 8 = 設計/業務ツール/モジュール境界.md の「組み込みの業務モジュールの置き場」。
     * 会議のルート 2 × 2 = 4 = 設計/業務ツール/会議/処理.md の 3.2・3.7。定義の管理・申請操作はポリシーなし)と、申請書の業務モジュールの 16(クラスの注)。
     */
    public function test_the_seeders_reuse_the_subjects(): void
    {
        $this->assertEquals(['policy' => 3, 'selection' => 6], AuthzSubjectGroup::query()->groupBy('purpose')->orderBy('purpose')->selectRaw('purpose, count(*) as subject_groups')->pluck('subject_groups', 'purpose')->all());
        $this->assertCount(162 + 16, $this->policySummaries());
    }

    public function test_a_guest_sees_the_home_and_the_sitemap(): void
    {
        $this->withoutVite();

        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Menu/Home'));
        $this->get('/menu/sitemap')->assertInertia(fn (Assert $page) => $page->component('Menu/Sitemap'));
    }

    /**
     * ポリシーを TX の中で全部消す(許可が 1 つも無ければゲストは 403 = 既定は拒否)。
     */
    public function test_a_guest_is_forbidden_an_app_route_without_any_policy(): void
    {
        AuthzPolicy::query()->delete();

        $this->get('/')->assertForbidden();
    }

    /**
     * 業務データの入れ物の初期データのポリシー(63 件 = 業務のルート 17 本 × 2・セット record-modules 5(全件の参照を含む)・業務モジュール 3 つ × 8。
     * docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md の 7 節。会議 = 設計/業務ツール/モジュール境界.md の「組み込みの業務モジュールの置き場」)。
     *
     * @return list<string>
     */
    private function recordsPolicySummaries(): array
    {
        $everyone = ['ゲストユーザ', '認証済みユーザ'];
        $moduleActions = array_map(fn (string $action) => "record-module:{$action}", ['read', 'create', 'update', 'delete']);
        $allModuleActions = [...$moduleActions, 'record-module:read-all'];
        $execute = ['service:execute'];
        $dataRoutes = [
            'index', 'create', 'store', 'show', 'edit', 'update', 'destroy', 'candidates',
            'attachments.store', 'attachments.destroy', 'attachments.show', 'attachments.preview',
            'comments.store', 'comments.destroy', 'comments.candidates',
            'revision-files.show', 'revision-files.preview',
        ];

        return [
            ...array_merge(...array_map(
                fn (string $route) => $this->permits("http-services.records.data.{$route}", $execute, $everyone),
                $dataRoutes,
            )),
            ...$this->permits('record-modules', $allModuleActions, ['システム管理者']),
            ...array_merge(...array_map(fn ($m) => $this->permits("record-module-{$m}", $moduleActions, $everyone), [
                'project', 'project_issue', 'meeting', 'project_plan_application', 'issue_resolution_application',
            ])),
        ];
    }

    /**
     * ワークフローの初期データのポリシー(ルート 31 本 × ゲストユーザ・認証済みユーザの execute = docs の 設計/ワークフロー/認可と一覧.md 4 節、ルートの深さ/認可と一覧.md 4.1)。
     *
     * @return list<string>
     */
    private function workflowPolicySummaries(): array
    {
        $routes = [
            'flows.index', 'applications.create', 'applications.store', 'drafts.store', 'drafts.edit', 'drafts.update', 'drafts.apply', 'drafts.destroy',
            'cases.index', 'cases.show', 'cases.approve', 'cases.approve-early', 'cases.reject', 'cases.send-back', 'cases.pull-back', 'cases.withdraw', 'cases.bulk-approve', 'cases.bulk-confirm', 'cases.hold', 'cases.release', 'cases.transfer', 'cases.confirm', 'target-values', 'record-candidates', 'proxies.index', 'proxies.store', 'proxies.destroy',
            'reapplications.create', 'reapplications.store', 'cases.assignee-candidates', 'user-candidates',
        ];

        return $this->everyonePolicySummaries('workflow', ...$routes);
    }

    /**
     * ゲストユーザ・認証済みユーザに execute を許すルートのポリシー(ワークフロー・通知・会議。出典は test_the_seeders_reuse_the_subjects の注)。
     *
     * @return list<string>
     */
    private function everyonePolicySummaries(string $prefix, string ...$routes): array
    {
        $everyone = ['ゲストユーザ', '認証済みユーザ'];

        return array_merge(...array_map(fn (string $route) => $this->permits("http-services.{$prefix}.{$route}", ['service:execute'], $everyone), $routes));
    }

    /**
     * リソースグループの、アクションごと・対象者ごとの許可の一覧(「リソースグループ ID タイプ:アクション permit 対象者」)。
     *
     * @param  list<string>  $typedActions  「タイプ:アクション」
     * @param  list<string>  $subjects  対象者の ja の表示名
     * @return list<string>
     */
    private function permits(string $resourceGroupCode, array $typedActions, array $subjects): array
    {
        $summaries = [];

        foreach ($typedActions as $typedAction) {
            foreach ($subjects as $subject) {
                $summaries[] = "{$resourceGroupCode} {$typedAction} permit {$subject}";
            }
        }

        return $summaries;
    }

    /**
     * ロールを 1 つだけ条件に持つ対象者。
     */
    private function subjectGroupWithRole(string $roleCode): AuthzSubjectGroup
    {
        return AuthzSubjectGroup::query()
            ->has('authzSubjectConditions', '=', 1)
            ->whereRelation('authzSubjectConditions.role', 'code', $roleCode)
            ->sole();
    }

    /**
     * セットの先頭ごとに、セットの中のリソースのタイプのすべてのアクション(「セットの ID タイプ:アクション」)。
     *
     * @return list<string>
     */
    private function everyActionOfEachSet(): array
    {
        $actions = app(ResourceTypeCatalog::class)->actionsByResourceType();
        $required = [];

        foreach (AuthzResourceGroup::query()->whereNull('parent_id')->get() as $set) {
            foreach ($this->resourceTypesUnder($set) as $type) {
                $required = [...$required, ...array_map(fn (string $action) => "{$set->code} {$type}:{$action}", $actions[$type])];
            }
        }

        return $required;
    }

    /**
     * セットと配下のリソースグループのリソースのタイプ。
     *
     * @return list<string>
     */
    private function resourceTypesUnder(AuthzResourceGroup $set): array
    {
        $ids = [$set->id];

        for ($level = $ids; $level !== [];) {
            $level = AuthzResourceGroup::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return array_values(AuthzResource::query()->whereIn('authz_resource_group_id', $ids)->get()
            ->map(fn (AuthzResource $resource) => $resource->resource_type)
            ->unique()
            ->all());
    }

    /**
     * ポリシーの一覧(リソースグループ ID・タイプ:アクション・エフェクト・対象者の ja の表示名)。
     *
     * @return list<string>
     */
    private function policySummaries(): array
    {
        return array_values(AuthzPolicy::query()->with(['authzResourceGroup', 'authzSubjectGroup.authzSubjectGroupTranslations.locale'])->get()
            ->map(fn (AuthzPolicy $policy) => "{$policy->authzResourceGroup?->code} {$policy->resource_type}:{$policy->action} {$policy->effect->value} "
                .$policy->authzSubjectGroup?->authzSubjectGroupTranslations->first(fn (AuthzSubjectGroupTranslation $translation) => $translation->locale?->code === 'ja')?->display_name)
            ->all());
    }
}
