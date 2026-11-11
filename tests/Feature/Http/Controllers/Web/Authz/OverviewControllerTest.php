<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Authz\CreateSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\OverviewQuery;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 認可設定の画面の props(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 4 節、認可の確認/認可設定の画面.md 1 節・2 節)。
 * 判定の口との一致は {@see OverviewGridConsistencyTest}、行の集合と並びは {@see OverviewRowOrderTest}、
 * 行の出し方の表とルートの束の判定は {@see OverviewRowTableTest}。
 * 初期データ(システム管理者 = u0004 伊藤 涼子、主所属は経営企画室。退職者 u0014、入社前の u0015)を入れ、
 * 今日を u0015 の入社の前の 2026-10-10 に固定し、ルートの認可を外さずに開く。
 */
final class OverviewControllerTest extends TransactionalTestCase
{
    use ReadsAuthzGrid;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
        $this->administrator = User::query()->where('code', 'u0004')->sole();
    }

    public function test_opens_the_service_grid_judged_as_oneself_by_default(): void
    {
        $props = $this->props($this->actingAs($this->administrator)->get(self::URL));

        $this->assertSame(['id' => $this->administrator->id, 'code' => 'u0004', 'name' => '伊藤 涼子'], $props['judge']['user']);
        $this->assertSame(['planning', '経営企画室'], [$props['judge']['department']['code'], $props['judge']['department']['name']]);
        $this->assertSame(['service', 'im-menu-group', 'record-module', 'workflow-flow'], $props['resourceTypes']);
        $this->assertSame(['service', ['execute']], [$props['grid']['resourceType'], $props['grid']['actions']]);
        // 列の束は認証が左(カテゴリの並びの表 = docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 5.2)
        $this->assertSame([
            ['ゲストユーザー', ['code' => 'im_authz_meta_subject', 'name' => 'ログインの状態'], false],
            ['ログイン済みユーザー', ['code' => 'im_authz_meta_subject', 'name' => 'ログインの状態'], true],
            ['システム管理者', ['code' => 'b_m_role', 'name' => 'ロール'], true],
        ], array_map(fn (array $subject) => [$subject['name'], $subject['category'], $subject['matches']], $props['grid']['subjects']));
    }

    public function test_the_built_in_columns_and_their_categories_follow_the_locale(): void
    {
        $requester = new Requester($this->administrator->id, null);
        $grid = app(OverviewQuery::class)->gridOn($requester, 'service', CarbonImmutable::now(), 'en', false);

        $this->assertSame([
            ['Guest user', 'Login status'],
            ['Logged-in user', 'Login status'],
            ['System administrator', 'Role'],
        ], array_map(fn (array $subject) => [$subject['name'], $subject['category']['name']], $grid['subjects']));
    }

    public function test_the_columns_are_the_subjects_for_policies_with_or_without_a_policy(): void
    {
        $withoutPolicy = app(CreateSubjectGroup::class)->execute(SubjectCombination::Any, [new SubjectConditionInput(userId: $this->administrator->id)], ['ja' => ['display_name' => 'ポリシーの無い対象者']]);
        $selection = app(CreateSelectionSubjectGroup::class)->execute([new SelectionCondition(SelectionConditionKind::User, $this->administrator->id)], ['ja' => '指定用の対象者']);

        $subjects = $this->grid($this->actingAs($this->administrator)->get(self::URL))['subjects'];

        $this->assertSame(['ゲストユーザー', 'ログイン済みユーザー', 'システム管理者', 'ポリシーの無い対象者'], array_column($subjects, 'name'));
        $this->assertSame($withoutPolicy->id, $subjects[3]['id']);
        $this->assertNotContains($selection, array_column($subjects, 'id'));
    }

    public function test_the_admin_menu_group_is_read_only_by_the_inherited_permit_of_the_administrator(): void
    {
        $response = $this->actingAs($this->administrator)->get(self::URL.'?type=im-menu-group');
        $administrator = $this->grid($response)['subjects'][2]['id'];
        $adminGroup = $this->row($response, 'im-menu-group-global-nav-admin');

        $this->assertSame(['im-menu-group', ['read', 'admin']], [$this->grid($response)['resourceType'], $this->grid($response)['actions']]);
        $this->assertSame([[$administrator, 'permit', 'im-menu-group']], $this->cells($adminGroup, 'read'));
        $this->assertSame(['action' => 'read', 'result' => 'permit', 'decidingSubjectGroupIds' => [$administrator]], $adminGroup['judgements'][0]);
    }

    public function test_the_type_query_selects_the_grid_and_falls_back_to_service(): void
    {
        $this->actingAs($this->administrator);

        $this->assertSame(
            ['record-module', ['read', 'create', 'update', 'delete', 'read-all']],
            $this->typeAndActions($this->get(self::URL.'?type=record-module')),
        );
        $this->assertSame(['service', ['execute']], $this->typeAndActions($this->get(self::URL.'?type=abc')));
    }

    public function test_a_record_module_is_read_all_only_by_the_inherited_permit_of_the_administrator(): void
    {
        $response = $this->actingAs($this->administrator)->get(self::URL.'?type=record-module');
        $administrator = $this->grid($response)['subjects'][2]['id'];
        $project = $this->row($response, 'record-module-project');

        $this->assertSame([[$administrator, 'permit', 'record-modules']], $this->cells($project, 'read-all'));
        $this->assertSame(
            ['action' => 'read-all', 'result' => 'permit', 'decidingSubjectGroupIds' => [$administrator]],
            array_column($project['judgements'], null, 'action')['read-all'],
        );
        $asGuest = $this->row($this->get(self::URL.'?user=guest&type=record-module'), 'record-module-project');
        $results = array_column($asGuest['judgements'], 'result', 'action');
        $this->assertSame(['permit', 'deny'], [$results['read'], $results['read-all']]);
    }

    /**
     * 束の配下のルート(組織図)を閉塞にすると、束「組織図」の判定が閉塞になり、セルは変わらない(束の判定 = 配下のルートの判定)。
     */
    public function test_a_bundle_over_a_blocked_route_is_judged_as_blocked_with_the_same_settings(): void
    {
        $bundle = 'http-services.master.organization-chart';
        $before = $this->row($this->actingAs($this->administrator)->get(self::URL), $bundle);
        $route = AuthzResourceGroup::query()->where('code', 'http-services.master.organization-chart.show')->sole();
        AuthzResourceGroupAttribute::factory()->blocked('service:execute')->create(['authz_resource_group_id' => $route->id]);

        $after = $this->row($this->get(self::URL), $bundle);

        $this->assertSame([['action' => 'execute', 'result' => 'block', 'decidingSubjectGroupIds' => []]], $after['judgements']);
        $this->assertSame($before['settings'], $after['settings']);
    }

    public function test_the_candidates_are_the_users_effective_today_in_order(): void
    {
        $codes = array_column($this->props($this->actingAs($this->administrator)->get(self::URL))['candidates'], 'code');

        $this->assertSame(['u0001', 'u0002', 'u0003', 'u0004', 'u0005', 'u0006', 'u0007', 'u0008', 'u0009', 'u0010', 'u0011', 'u0012', 'u0013', 'u0016', 'u0017'], $codes);
    }

    public function test_judges_as_a_guest(): void
    {
        $response = $this->actingAs($this->administrator)->get(self::URL.'?user=guest');
        $guest = $this->grid($response)['subjects'][0]['id'];

        $this->assertSame(['user' => null, 'department' => null], $this->props($response)['judge']);
        $this->assertSame([true, false, false], array_column($this->grid($response)['subjects'], 'matches'));
        $this->assertSame([['action' => 'execute', 'result' => 'permit', 'decidingSubjectGroupIds' => [$guest]]], $this->row($response, 'http-services.master.organization-chart')['judgements']);
        $adminGroup = $this->row($this->get(self::URL.'?user=guest&type=im-menu-group'), 'im-menu-group-global-nav-admin');
        $this->assertSame(['action' => 'read', 'result' => 'deny', 'decidingSubjectGroupIds' => []], $adminGroup['judgements'][0]);
    }

    public function test_judges_as_another_user_with_the_main_membership(): void
    {
        $user = User::query()->where('code', 'u0001')->sole();

        $response = $this->actingAs($this->administrator)->get(self::URL."?user={$user->id}");

        $this->assertSame(['u0001', 'tokyo-sales'], [$this->props($response)['judge']['user']['code'], $this->props($response)['judge']['department']['code']]);
        $authz = $this->row($response, 'http-services.authz.settings');
        $this->assertSame('deny', $authz['judgements'][0]['result']);
    }

    #[TestWith(['abc'])]
    #[TestWith(['99999999'])]
    #[TestWith(['u0014'])]
    #[TestWith(['u0015'])]
    public function test_an_unreadable_or_not_effective_user_falls_back_to_oneself(string $user): void
    {
        $value = str_starts_with($user, 'u00') ? (string) User::query()->where('code', $user)->sole()->id : $user;

        $this->assertSame('u0004', $this->props($this->actingAs($this->administrator)->get(self::URL.'?user='.$value))['judge']['user']['code']);
    }

    public function test_a_partial_reload_of_the_judgement_does_not_build_the_candidates_nor_the_types(): void
    {
        $this->actingAs($this->administrator)->get(self::URL.'?user=guest')->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['grid', 'judge'], fn (Assert $reload) => $reload
                ->missing('candidates')
                ->missing('resourceTypes')
                ->where('judge.user', null)
                ->where('grid.resourceType', 'service')
            )
        );
    }

    public function test_a_guest_and_a_user_without_the_role_are_forbidden(): void
    {
        $this->get(self::URL)->assertForbidden();
        $this->actingAs(User::query()->where('code', 'u0001')->sole())->get(self::URL)->assertForbidden();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array{mixed, mixed}
     */
    private function typeAndActions(TestResponse $response): array
    {
        return [$this->grid($response)['resourceType'], $this->grid($response)['actions']];
    }
}
