<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Records\RecordModuleAction;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\ApplicableFlows;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * フローの一覧と申請できる人の判定(docs の 設計/ワークフロー/認可と一覧.md 3.1・7 節の「フローの一覧」、案件の操作/README.md 1.3): 申請のノードの処理対象者の指定に
 * 本人の判定(カレント組織 1 つ)で合い、コンテンツの業務モジュールの create を許される人だけに出る。ゲスト・d に有効でないユーザーは空。並び = sort_key・code。
 * フロー・業務モジュールはテストで作り、初期データのユーザー・組織・役職・ロールを使う。一覧はテストで作ったフローだけに絞って比べる。
 */
final class ApplicableFlowsTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsWorkflowCases;
    use UsesSeededRecords;

    private const DATE = '2026-10-13';

    /** @var list<string> テストで作ったフローのコード */
    private array $flowCodes = [];

    /**
     * 申請のノードの指定(ユーザー・ロール・組織・役職)に合う人だけに出る。u0001 = 東京営業課の一般、u0002 = 東京営業課の課長でワークフロー管理者、
     * u0003 = 第一営業部の部長。
     */
    public function test_lists_the_flows_whose_apply_node_the_person_matches(): void
    {
        [$u0001, $u0002, $u0003] = $this->seededUsers('u0001', 'u0002', 'u0003');
        $this->flow('by_user', SelectionConditionKind::User, $u0001->id);
        $this->flow('by_role', SelectionConditionKind::Role, Role::query()->where('code', 'workflow_admin')->sole()->id);
        $this->flow('by_department', SelectionConditionKind::Department, $this->departmentId('tokyo-sales'));
        $this->flow('by_post', SelectionConditionKind::CompanyPost, CompanyPost::query()->where('code', 'section-chief')->sole()->id);

        $this->assertSame(['by_department', 'by_user'], $this->codesFor($u0001));
        $this->assertSame(['by_department', 'by_post', 'by_role'], $this->codesFor($u0002));
        $this->assertSame([], $this->codesFor($u0003));
    }

    public function test_a_flow_by_department_follows_the_current_department(): void
    {
        [$u0009] = $this->seededUsers('u0009');
        $this->flow('by_department', SelectionConditionKind::Department, $this->departmentId('human-resources'));

        $this->assertSame([], $this->codesFor($u0009));
        $this->assertSame(['by_department'], $this->codesFor($u0009, $this->departmentId('human-resources')));
    }

    public function test_a_flow_whose_module_denies_create_is_not_listed(): void
    {
        [$u0001] = $this->seededUsers('u0001');
        $denied = $this->flow('denied', SelectionConditionKind::User, $u0001->id);
        $this->flow('allowed', SelectionConditionKind::User, $u0001->id);

        $this->deny('m_denied', RecordModuleAction::Create);

        $this->assertSame(['allowed'], $this->codesFor($u0001));
        $this->assertFalse($this->flows()->isApplicable($this->requester($u0001), self::day(), $denied->id));
    }

    /**
     * 申請のノードは d に有効な版のもの。d に有効な版の無いフロー(版が後で始まる・d で終わった・無効)は出ない(段 3a の ルートの深さ/データモデル/定義の表.md 2.1)。
     */
    public function test_a_flow_without_a_version_valid_on_the_date_is_not_listed(): void
    {
        [$u0001] = $this->seededUsers('u0001');
        $this->flow('valid', SelectionConditionKind::User, $u0001->id);
        $versions = [
            ['start_date' => '2026-10-14'],
            ['end_date' => self::DATE],
            ['is_deleted' => true],
        ];

        foreach ($versions as $index => $columns) {
            $flow = $this->flow("invalid_{$index}", SelectionConditionKind::User, $u0001->id);
            WorkflowFlowVersion::query()->where('workflow_flow_id', $flow->id)->update($columns);
        }

        $this->assertSame(['valid'], $this->codesFor($u0001));
    }

    /**
     * ゲストと d に有効でないユーザー(退職)は、指定に当たっても申請できない。
     */
    public function test_a_guest_and_a_user_not_effective_on_the_date_get_nothing(): void
    {
        [$retired] = $this->seededUsers('u0014');
        $flow = $this->flow('by_user', SelectionConditionKind::User, $retired->id);

        $this->assertSame([], $this->codesFor($retired));
        $this->assertSame([], $this->codesFor(null));
        $this->assertFalse($this->flows()->isApplicable($this->requester($retired), self::day(), $flow->id));
        $this->assertFalse($this->flows()->isApplicable($this->requester(null), self::day(), $flow->id));
    }

    public function test_orders_by_sort_key_then_code_and_returns_the_listed_columns(): void
    {
        [$u0001] = $this->seededUsers('u0001');
        $this->flow('b_flow', SelectionConditionKind::User, $u0001->id, sortKey: 2, description: '説明');
        $this->flow('z_flow', SelectionConditionKind::User, $u0001->id, sortKey: 1);
        $this->flow('a_flow', SelectionConditionKind::User, $u0001->id, sortKey: 1);

        $flows = array_values(array_filter($this->flows()->flows($this->context($u0001)), fn (array $flow) => in_array($flow['code'], $this->flowCodes, true)));

        $this->assertSame([
            ['code' => 'a_flow', 'name' => 'a_flow の名前', 'description' => null, 'moduleName' => 'm_a_flow'],
            ['code' => 'z_flow', 'name' => 'z_flow の名前', 'description' => null, 'moduleName' => 'm_z_flow'],
            ['code' => 'b_flow', 'name' => 'b_flow の名前', 'description' => '説明', 'moduleName' => 'm_b_flow'],
        ], $flows);
    }

    /**
     * isApplicable と applicableIds は、一覧と同じ判定(一覧に出るフローだけが真)。
     */
    public function test_is_applicable_uses_the_same_judgement_as_the_list(): void
    {
        [$u0001, $u0002] = $this->seededUsers('u0001', 'u0002');
        $flows = [
            $this->flow('by_user', SelectionConditionKind::User, $u0001->id),
            $this->flow('by_role', SelectionConditionKind::Role, Role::query()->where('code', 'workflow_admin')->sole()->id),
            $this->flow('denied', SelectionConditionKind::User, $u0002->id),
        ];
        $this->deny('m_denied', RecordModuleAction::Create);
        $flowIds = array_map(fn (WorkflowFlow $flow) => $flow->id, $flows);

        foreach ([$u0001, $u0002] as $user) {
            $listed = $this->codesFor($user);
            $applicable = array_values(array_map(fn (WorkflowFlow $flow) => $flow->code, array_filter($flows, fn (WorkflowFlow $flow) => $this->flows()->isApplicable($this->requester($user), self::day(), $flow->id))));
            $applicableIds = $this->flows()->applicableIds($this->requester($user), self::day(), $flowIds);

            sort($applicable);
            $this->assertSame($listed, $applicable);
            $this->assertSame($applicableIds, array_values(array_filter($flowIds, fn (int $id) => in_array(WorkflowFlow::query()->findOrFail($id)->code, $listed, true))));
        }
    }

    private function flows(): ApplicableFlows
    {
        return app(ApplicableFlows::class);
    }

    private static function day(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::DATE);
    }

    private function requester(?User $user): Requester
    {
        return new Requester($user?->id, null);
    }

    private function context(?User $user, ?int $selectedDepartmentId = null): AccessContext
    {
        return new AccessContext($user?->id, null, $selectedDepartmentId, self::day(), 'ja');
    }

    /**
     * フローの一覧のうちテストで作ったフローのコード(コードの順に並べ替える。並びは別のテストで確かめる)。
     *
     * @return list<string>
     */
    private function codesFor(?User $user, ?int $selectedDepartmentId = null): array
    {
        $codes = array_values(array_filter(array_column($this->flows()->flows($this->context($user, $selectedDepartmentId)), 'code'), fn (string $code) => in_array($code, $this->flowCodes, true)));
        sort($codes);

        return $codes;
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    /**
     * フロー(コンテンツ = 業務モジュール m_<コード>(全員に 4 つのアクションを許す)、版 1 つ(2000-01-01 から上限なし)のルート = 申請(渡した条件 1 つの対象者)+ 承認)。
     */
    private function flow(string $code, SelectionConditionKind $kind, int $id, int $sortKey = 0, ?string $description = null): WorkflowFlow
    {
        $flow = WorkflowFlow::factory()->create([
            'code' => $code,
            'name' => "{$code} の名前",
            'description' => $description,
            'workflow_content_id' => WorkflowContent::factory()->create(['record_module_id' => $this->recordModule("m_{$code}")->id])->id,
            'sort_key' => $sortKey,
        ]);
        $version = WorkflowFlowVersion::factory()->create(['workflow_flow_id' => $flow->id]);
        WorkflowRouteNode::factory()->create([
            'workflow_flow_version_id' => $version->id,
            'position' => 1,
            'authz_subject_group_id' => $this->selection($kind, $id),
        ]);
        WorkflowRouteNode::factory()->create(['workflow_flow_version_id' => $version->id, 'position' => 2]);
        $this->flowCodes[] = $code;

        return $flow;
    }
}
