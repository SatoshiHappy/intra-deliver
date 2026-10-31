<?php

namespace Tests\Feature\Http\Middleware\Menu;

use App\Actions\Records\CreateModuleCategory;
use App\Actions\Records\SetModuleCategoryModules;
use App\Actions\Records\UpdateRecordModule;
use App\Http\Middleware\Menu\ShareAppNavigation;
use App\Models\Authz\AuthzPolicy;
use App\Models\Master\User;
use Illuminate\Http\Request;
use Inertia\AlwaysProp;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 初期データの上の今のアプリと現在地のパス(docs の 設計/メニュー/表示.md 3.3.2 の当てはめの表と表の下の段落、3.3.7 の「認可の条件で表示する
 * アプリが変わる組み合わせ」「現在地の強調」「先頭の画面」)。骨格の共有({@see ShareAppNavigation})を、画面のパスの要求で直に通して
 * appNavigation の値を読む(画面を描かないので、開けない URL の形でも今のアプリを確かめられる)。判定を求める者はゲスト・u0001・システム管理者 u0004。
 *
 * @phpstan-type App array{code: string, entry: array{href: string, isExternal: bool}, children: list<mixed>}
 * @phpstan-type Navigation array{
 *     launcher: list<App>, adminMenu: list<App>,
 *     currentApp: string|null, currentItemPath: string|null, showSitemap: bool,
 * }
 */
final class CurrentAppOnSeededDataTest extends TransactionalTestCase
{
    private const WORKFLOW = 'global-nav-workflow';

    private const WORK = 'global-nav-main';

    private const ADMIN = 'global-nav-admin';

    /**
     * 当てはめの表の各行(システム管理者)。
     *
     * @return array<string, array{string, string|null}>
     */
    public static function fittings(): array
    {
        return [
            '/' => ['/', null],
            '/menu/sitemap' => ['/menu/sitemap', null],
            '/notices' => ['/notices', null],
            '/master/organization-chart' => ['/master/organization-chart', self::ADMIN],
            '/authz/overview' => ['/authz/overview', self::ADMIN],
            '/records/admin/modules/project/fields/create' => [
                '/records/admin/modules/project/fields/create', self::ADMIN,
            ],
            '/records/admin/categories' => ['/records/admin/categories', self::ADMIN],
            '/records/data/project/5' => ['/records/data/project/5', self::WORK],
            '/records/data/project_plan_application/2' => ['/records/data/project_plan_application/2', self::WORKFLOW],
            '/workflow' => ['/workflow', self::WORKFLOW],
            '/workflow/drafts/5/edit' => ['/workflow/drafts/5/edit', self::WORKFLOW],
            '/workflow/cases/42' => ['/workflow/cases/42', self::WORKFLOW],
            '/workflow/flows/project_plan/apply' => ['/workflow/flows/project_plan/apply', self::WORKFLOW],
        ];
    }

    #[DataProvider('fittings')]
    public function test_the_current_app_of_each_row_of_the_fitting_table(string $path, ?string $expected): void
    {
        $this->assertSame($expected, $this->navigationOf('u0004', $path)['currentApp']);
    }

    /**
     * 非表示の行: 申請書でない業務モジュールを非表示にすると、その一覧は業務とワークフローのアイテムが k = 2 で同点になり、並びでワークフロー。
     * 現在地のパスは無い(上の階層でない申請書のアイテムに付かない)。
     */
    public function test_the_list_of_a_hidden_module_belongs_to_the_workflow_without_a_current_item(): void
    {
        app(UpdateRecordModule::class)->execute('project_issue', ['is_visible' => false]);

        $navigation = $this->navigationOf('u0004', '/records/data/project_issue');

        $this->assertSame([self::WORKFLOW, null], [$navigation['currentApp'], $navigation['currentItemPath']]);
    }

    /**
     * 管理のグループを読めない人(ゲストと u0001)では、組織図の今のアプリは無し。
     */
    #[TestWith([null])]
    #[TestWith(['u0001'])]
    public function test_the_organization_chart_has_no_current_app_without_the_admin_app(?string $user): void
    {
        $this->assertNull($this->navigationOf($user, '/master/organization-chart')['currentApp']);
    }

    /**
     * ワークフローのグループの read を外した人の /workflow は無し(管理の /workflow/admin/… と同点でも、次の候補に移らない)。
     * そのアプリはランチャーにも出ない(別のアプリにならない)。
     */
    #[TestWith([null])]
    #[TestWith(['u0001'])]
    public function test_without_the_read_of_the_workflow_its_screens_have_no_current_app(?string $user): void
    {
        AuthzPolicy::query()->whereRelation('authzResourceGroup', 'code', 'im-menu-group-'.self::WORKFLOW)->delete();

        $navigation = $this->navigationOf($user, '/workflow');

        $this->assertNull($navigation['currentApp']);
        $this->assertSame([self::WORK], $this->codesOf($navigation['launcher']));
    }

    /**
     * ランチャーのアプリは 3 人とも ワークフロー・業務 の順。歯車と管理はシステム管理者にだけ。
     *
     * @param  list<string>  $adminMenu
     */
    #[TestWith([null, []])]
    #[TestWith(['u0001', []])]
    #[TestWith(['u0004', [self::ADMIN]])]
    public function test_the_launcher_and_the_admin_menu_of_each_requester(?string $user, array $adminMenu): void
    {
        $navigation = $this->navigationOf($user, '/');

        $this->assertSame([self::WORKFLOW, self::WORK], $this->codesOf($navigation['launcher']));
        $this->assertSame($adminMenu, $this->codesOf($navigation['adminMenu']));
    }

    /**
     * 一部のアイテムの execute だけを外しても今のアプリは変わらない(そのアイテムが木と現在地から消えるだけ)。
     */
    #[TestWith([null])]
    #[TestWith(['u0001'])]
    public function test_without_the_execute_of_an_item_the_current_app_stays(?string $user): void
    {
        $before = $this->navigationOf($user, '/workflow/cases/42');
        $route = 'http-services.workflow.cases.index';
        AuthzPolicy::query()->whereRelation('authzResourceGroup', 'code', $route)->delete();
        $after = $this->navigationOf($user, '/workflow/cases/42');

        $this->assertSame([self::WORKFLOW, '/workflow/cases'], [$before['currentApp'], $before['currentItemPath']]);
        $this->assertSame([self::WORKFLOW, '/workflow'], [$after['currentApp'], $after['currentItemPath']]);
    }

    /**
     * execute を許されないアイテムだけのアプリは出ない(業務の一覧のルートの許可を外すと、業務のアプリが消える)。
     */
    #[TestWith([null])]
    #[TestWith(['u0001'])]
    public function test_an_app_whose_items_are_all_not_executable_is_not_shown(?string $user): void
    {
        AuthzPolicy::query()->whereRelation('authzResourceGroup', 'code', 'http-services.records.data.index')->delete();

        $navigation = $this->navigationOf($user, '/records/data/project/5');

        $this->assertSame([self::WORKFLOW], $this->codesOf($navigation['launcher']));
        $this->assertNull($navigation['currentApp']);
    }

    /**
     * 現在地のパス: 3.3.2 の例の 4 つと、今のアプリが無い画面の null。
     */
    #[TestWith(['/workflow', '/workflow'])]
    #[TestWith(['/workflow/cases/42', '/workflow/cases'])]
    #[TestWith(['/records/data/project/5', '/records/data/project'])]
    #[TestWith(['/workflow/drafts/5/edit', '/workflow'])]
    #[TestWith(['/', null])]
    #[TestWith(['/menu/sitemap', null])]
    public function test_the_current_item_path_of_the_examples(string $path, ?string $expected): void
    {
        $this->assertSame($expected, $this->navigationOf('u0001', $path)['currentItemPath']);
    }

    /**
     * 2 つの分類に属す業務モジュールの詳細: 2 つのアイテムが同じパスなので、現在地のパスは 1 つ。
     */
    public function test_the_detail_of_a_module_in_two_categories_has_one_current_item_path(): void
    {
        foreach (['営業', '開発'] as $name) {
            $categoryId = app(CreateModuleCategory::class)->execute(['name' => $name]);
            app(SetModuleCategoryModules::class)->execute($categoryId, ['project']);
        }

        $navigation = $this->navigationOf('u0001', '/records/data/project/5');

        // 業務のアプリの木(先頭の画面 entry を除く)に、同じパスのアイテムが 2 つ
        $tree = (string) json_encode(array_column($navigation['launcher'], 'children', 'code')[self::WORK] ?? null);
        $this->assertSame(2, substr_count($tree, '"href":"\/records\/data\/project"'));
        $this->assertSame(self::WORK, $navigation['currentApp']);
        $this->assertSame('/records/data/project', $navigation['currentItemPath']);
    }

    /**
     * 先頭の画面: 3 人それぞれに見えるすべてのアプリで、entry のパスの今のアプリがそのアプリになる。
     */
    #[TestWith([null])]
    #[TestWith(['u0001'])]
    #[TestWith(['u0004'])]
    public function test_the_entry_of_every_visible_app_opens_that_app(?string $user): void
    {
        $navigation = $this->navigationOf($user, '/');
        $apps = [...$navigation['launcher'], ...$navigation['adminMenu']];

        foreach ($apps as $app) {
            $this->assertFalse($app['entry']['isExternal'], $app['code']);
            $opened = $this->navigationOf($user, $app['entry']['href']);
            $this->assertSame($app['code'], $opened['currentApp'], $app['code']);
        }

        $this->assertNotSame([], $apps);
    }

    /**
     * 骨格の共有を画面のパスの要求で通し、appNavigation の値を読む(判定はクロージャの中で走る)。
     *
     * @return Navigation
     */
    private function navigationOf(?string $userCode, string $path): array
    {
        $user = $userCode === null ? null : User::query()->where('code', $userCode)->sole();
        $request = Request::create($path);
        $request->setUserResolver(fn () => $user);
        Inertia::flushShared();

        app(ShareAppNavigation::class)->handle($request, fn () => new Response);
        $shared = Inertia::getShared('appNavigation');
        $this->assertInstanceOf(AlwaysProp::class, $shared);

        /** @var Navigation $navigation */
        $navigation = $shared();

        return $navigation;
    }

    /**
     * @param  list<array{code: string}>  $groups
     * @return list<string>
     */
    private function codesOf(array $groups): array
    {
        return array_column($groups, 'code');
    }
}
