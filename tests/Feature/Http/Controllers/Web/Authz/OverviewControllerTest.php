<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Authz\CreateSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 認可設定の画面の props(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 4 節、認可の確認/認可設定の画面.md 1 節・2 節)。
 * 判定の口との一致は {@see OverviewGridConsistencyTest}。
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
            ['ゲストユーザ', ['code' => 'im_authz_meta_subject', 'name' => '認証'], false],
            ['認証済みユーザ', ['code' => 'im_authz_meta_subject', 'name' => '認証'], true],
            ['システム管理者', ['code' => 'b_m_role', 'name' => 'ロール'], true],
        ], array_map(fn (array $subject) => [$subject['name'], $subject['category'], $subject['matches']], $props['grid']['subjects']));
    }

    public function test_the_columns_are_the_subjects_for_policies_with_or_without_a_policy(): void
    {
        $withoutPolicy = app(CreateSubjectGroup::class)->execute(SubjectCombination::Any, [new SubjectConditionInput(userId: $this->administrator->id)], ['ja' => ['display_name' => 'ポリシーの無い対象者']]);
        $selection = app(CreateSelectionSubjectGroup::class)->execute([new SelectionCondition(SelectionConditionKind::User, $this->administrator->id)], ['ja' => '指定用の対象者']);

        $subjects = $this->grid($this->actingAs($this->administrator)->get(self::URL))['subjects'];

        $this->assertSame(['ゲストユーザ', '認証済みユーザ', 'システム管理者', 'ポリシーの無い対象者'], array_column($subjects, 'name'));
        $this->assertSame($withoutPolicy->id, $subjects[3]['id']);
        $this->assertNotContains($selection, array_column($subjects, 'id'));
    }

    public function test_a_route_row_has_direct_and_inherited_settings_and_the_judgement(): void
    {
        $response = $this->actingAs($this->administrator)->get(self::URL);
        [$guest, $authenticated, $administrator] = array_column($this->grid($response)['subjects'], 'id');
        $chart = $this->row($response, 'http-services.master.organization-chart');

        $this->assertSame([[$guest, 'permit', null], [$authenticated, 'permit', null], [$administrator, 'permit', 'http-services']], $this->cells($chart, 'execute'));
        $this->assertSame(['code' => 'http-services', 'name' => '画面・処理'], $chart['settings'][2]['inheritedFrom']);
        $this->assertSame([['action' => 'execute', 'result' => 'permit', 'decidingSubjectGroupIds' => [$authenticated, $administrator]]], $chart['judgements']);
        $master = $this->row($response, 'http-services.master');
        $this->assertSame([[$administrator, 'permit', 'http-services']], $this->cells($master, 'execute'));
        $this->assertNull($master['judgements']);
    }

    public function test_the_rows_are_the_holders_of_the_type_and_their_ancestors_in_depth_first_order(): void
    {
        $root = $this->resourceGroup('zz-root', null, 0, false);
        $this->resourceGroup('zz-root.no-holder', $root, 0, false);
        $this->resourceGroup('zz-root.b', $root, 1, true);
        $this->resourceGroup('zz-root.a', $root, 2, true);
        $this->resourceGroup('zz-root.d', $root, 3, true);
        $this->resourceGroup('zz-root.c', $root, 3, true);
        $this->resourceGroup('zz-root.f.g', $this->resourceGroup('zz-root.f', $root, 4, false), 0, true);

        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL))['rows'];

        $this->assertSame(['http-services', 0, null, null], [$rows[0]['code'], $rows[0]['depth'], $rows[0]['parentId'], $rows[0]['judgements']]);
        $this->assertSame(
            ['zz-root', 'zz-root.b', 'zz-root.a', 'zz-root.c', 'zz-root.d', 'zz-root.f', 'zz-root.f.g'],
            array_values(array_filter(array_column($rows, 'code'), fn (string $code) => str_starts_with($code, 'zz-root'))),
            'リソースを持つ行と祖先だけ。兄弟は sort_key・code の順',
        );
        $this->assertDepthFirst($rows);
        $this->assertLeavesHold($rows, 'service');
        $this->assertSiblingOrder($rows);

        foreach ($rows as $row) {
            $this->assertSame(['resourceGroupId', 'parentId', 'depth', 'code', 'name', 'settings', 'judgements'], array_keys($row));
        }
    }

    public function test_siblings_with_all_digit_codes_are_in_the_order_of_the_database(): void
    {
        $root = $this->resourceGroup('zz-digits', null, 0, false);
        $this->resourceGroup('2', $root, 0, true);
        $this->resourceGroup('10', $root, 0, true);

        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL))['rows'];

        $this->assertSame(
            ['zz-digits', '10', '2'],
            array_values(array_filter(array_column($rows, 'code'), fn (string $code) => in_array($code, ['zz-digits', '2', '10'], true))),
            '同じ sort_key の数字だけのコードは、数値でなく DB の文字列の順(10 → 2)',
        );
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

    public function test_a_blocked_row_is_judged_as_blocked_with_the_same_settings(): void
    {
        $code = 'http-services.master.organization-chart';
        $before = $this->row($this->actingAs($this->administrator)->get(self::URL), $code);
        AuthzResourceGroupAttribute::factory()->blocked('service:execute')->create(['authz_resource_group_id' => AuthzResourceGroup::query()->where('code', $code)->sole()->id]);

        $after = $this->row($this->get(self::URL), $code);

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
        $this->assertSame('deny', $this->row($response, 'http-services.authz.overview')['judgements'][0]['result']);
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
     * 行が深さ優先(先頭から下へ。親の行は直前の行かその祖先)で、parentId が親のリソースグループ、depth が先頭からの深さ。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertDepthFirst(array $rows): void
    {
        $parentIds = AuthzResourceGroup::query()->pluck('parent_id', 'id');
        $path = [];

        foreach ($rows as $row) {
            while ($path !== [] && end($path) !== $row['parentId']) {
                array_pop($path);
            }

            $this->assertSame([$parentIds->get($row['resourceGroupId']), $row['parentId'] === null ? [] : [$row['parentId']], count($path)], [$row['parentId'], array_slice($path, -1), $row['depth']], "{$row['code']} の親と深さ");
            $path[] = $row['resourceGroupId'];
        }
    }

    /**
     * 葉の行(子の行が無い行)は、どれもリソースタイプ $resourceType のリソースを持つ(持たない葉が混ざらない)。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertLeavesHold(array $rows, string $resourceType): void
    {
        $holders = AuthzResource::query()->where('resource_type', $resourceType)->pluck('authz_resource_group_id')->all();
        $parentIds = array_column($rows, 'parentId');

        foreach ($rows as $row) {
            if (! in_array($row['resourceGroupId'], $parentIds, true)) {
                $this->assertContains($row['resourceGroupId'], $holders, "{$row['code']} は子の行が無いのに {$resourceType} のリソースを持たない");
            }
        }
    }

    /**
     * 兄弟(同じ親の行。先頭どうしを含む)の並びが sort_key・code。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertSiblingOrder(array $rows): void
    {
        $sortKeys = AuthzResourceGroup::query()->pluck('sort_key', 'id');

        foreach (collect($rows)->groupBy(fn (array $row) => (string) $row['parentId']) as $siblings) {
            $keys = $siblings->map(fn (array $row) => [$sortKeys->get($row['resourceGroupId']), $row['code']])->all();
            $sorted = $keys;
            usort($sorted, fn (array $a, array $b) => $a <=> $b);
            $this->assertSame($sorted, $keys);
        }
    }

    /**
     * リソースグループ(holdsService = 画面・処理のリソースを 1 つ持つ)。
     */
    private function resourceGroup(string $code, ?AuthzResourceGroup $parent, int $sortKey, bool $holdsService): AuthzResourceGroup
    {
        $group = AuthzResourceGroup::factory()->create(['code' => $code, 'parent_id' => $parent?->id, 'sort_key' => $sortKey]);

        if ($holdsService) {
            AuthzResource::factory()->create(['authz_resource_group_id' => $group->id, 'resource_type' => 'service']);
        }

        return $group;
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
