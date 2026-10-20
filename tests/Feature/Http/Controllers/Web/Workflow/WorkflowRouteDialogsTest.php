<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsRouteVersions;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の詳細のステップごとの操作と処理のダイアログの値(docs の 設計/ワークフロー/ルートの深さ/画面.md 6・7 節の nodeActions): 指定するステップ(種類の語・人数・
 * 可決の条件・今の指定の初期値)、差戻しの候補と制限の 1 行、縦配置の子の否認の文、並列の 2 つのノードでの差戻しの可否、
 * 申請者承認防止(処理対象者が複数で申請者がその 1 人 = モック 6a の blockedReason と 422、申請者だけになる申請の 422)、履歴の指定。
 * 初期データの版 3 と、テストのフローに足した版を使う。ルートの認可を外さない。
 */
final class WorkflowRouteDialogsTest extends TransactionalTestCase
{
    use AppliesOnTheBranchRoute;
    use BuildsRouteVersions;
    use UsesSeededCases;

    /** Inertia の要求の見出し(検証の失敗を元の画面のエラーに戻す) */
    private const INERTIA = ['X-Inertia' => 'true'];

    private const BLOCKED = '申請者本人は、この申請を承認できません。このステップには、申請者承認防止が設定されています。';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 課長の承認のダイアログ: 指定するステップ 2 つ(縦配置「関係部署の合議」2〜5 人・可決の条件 人数 2、承認「責任者」1 人)で初期値は空。差戻しの候補 = 申請(制限は効いていない)。
     */
    public function test_the_section_chief_designates_the_council_and_the_owner(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->actingAsUser('u0002');

        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('case.currentSummary', ['state' => 'waiting', 'name' => '課長'])
            ->where('case.version', 3)
            ->where('nodeActions', [[
                'nodeId' => $this->nodeIdOf($case, 'section_chief'), 'name' => '課長', 'approve' => true, 'approveEarly' => true, 'reject' => true, 'sendBack' => true,
                'blockedReason' => null, 'sendBackTargets' => [['id' => $this->nodeIdOf($case, 'apply'), 'name' => '申請']], 'sendBackRestricted' => false, 'isCouncilVote' => false,
                'designations' => [
                    ['nodeId' => $this->nodeIdOf($case, 'council'), 'name' => '関係部署の合議', 'kind' => 'parallel', 'kindLabel' => '縦配置', 'min' => 2, 'max' => 5, 'decision' => ['rule' => 'count', 'value' => 2, 'required' => [2, 2, 2, 2, 2]], 'initial' => []],
                    ['nodeId' => $this->nodeIdOf($case, 'owner'), 'name' => '責任者', 'kind' => 'approve', 'kindLabel' => '承認', 'min' => 1, 'max' => 1, 'decision' => null, 'initial' => []],
                ],
            ]])
        );
    }

    /**
     * 縦配置の完了の条件の必要な数 k は、人数 1〜最大ごとの表 decision.required で渡す(式は WorkflowDecisionRule::required() の 1 か所。画面は表を引くだけ)。
     * 全員 = 人数と同じ、割合 50% = 人数の半分の切り上げ(1・1・2・2・3)、人数 2 = 2 のまま。
     */
    public function test_the_council_decision_carries_the_required_count_for_each_number_of_assignees(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $council = $this->nodeIdOf($case, 'council');
        $this->actingAsUser('u0002');
        $requiredOf = fn (): array => $this->get("/workflow/cases/{$case}")->assertOk()->viewData('page')['props']['nodeActions'][0]['designations'][0]['decision'];

        WorkflowCaseNode::query()->whereKey($council)->update(['decision_rule' => 'all', 'decision_value' => null]);
        $this->assertSame(['rule' => 'all', 'value' => null, 'required' => [1, 2, 3, 4, 5]], $requiredOf());

        WorkflowCaseNode::query()->whereKey($council)->update(['decision_rule' => 'ratio', 'decision_value' => 50]);
        $this->assertSame(['rule' => 'ratio', 'value' => 50, 'required' => [1, 1, 2, 2, 3]], $requiredOf());
    }

    /**
     * 合議の子のダイアログ: 差戻しの候補は差戻しできる先の申請・課長だけ(部長は祖先の処理済みだが外れる = 制限が効く)、縦配置の子、指定は無い。課長の承認の行の履歴に指定。
     * 子が課長へ差戻した後、課長の指定の欄の初期値は前の指定(合議 = u0009・u0010・u0016、責任者 = u0003)。
     */
    public function test_a_council_child_sends_back_only_to_the_allowed_nodes_and_the_designator_sees_the_previous_assignments(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->approveOnBranch($case, 'u0002', ['council' => ['u0009', 'u0010', 'u0016'], 'owner' => ['u0003']]);
        $this->approveOnBranch($case, 'u0003');
        $child = WorkflowCaseNode::query()->where('parent_workflow_case_node_id', $this->nodeIdOf($case, 'council'))->where('sequence', 1)->sole();
        $this->actingAsUser('u0009');

        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('case.currentSummary.name', '関係部署の合議 承認 1、関係部署の合議 承認 2、関係部署の合議 承認 3')
            ->where('nodeActions.0.nodeId', $child->id)
            ->where('nodeActions.0.name', '関係部署の合議 承認 1')
            ->where('nodeActions.0.sendBackTargets', [['id' => $this->nodeIdOf($case, 'apply'), 'name' => '申請'], ['id' => $this->nodeIdOf($case, 'section_chief'), 'name' => '課長']])
            ->where('nodeActions.0.sendBackRestricted', true)
            ->where('nodeActions.0.isCouncilVote', true)
            ->where('nodeActions.0.designations', [])
            ->where('histories.1.assignments', fn (Collection $rows) => $rows->map(fn (array $row) => [$row['nodeName'], array_column($row['people'], 'code')])->all() === [
                ['関係部署の合議', ['u0009', 'u0010', 'u0016']], ['責任者', ['u0003']],
            ])
        );

        $this->post("/workflow/cases/{$case}/send-back", ['revision' => $this->revisionOfCase($case), 'comment' => null, 'node_id' => $child->id, 'send_back_node_id' => $this->nodeIdOf($case, 'section_chief')], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors();
        $this->actingAsUser('u0002');
        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nodeActions.0.designations', fn (Collection $designations) => $designations->map(fn (array $row) => [$row['name'], array_column($row['initial'], 'code')])->all() === [
                ['関係部署の合議', ['u0009', 'u0010', 'u0016']], ['責任者', ['u0003']],
            ])
        );
    }

    /**
     * 課長が自分の承認を引戻した後も、指定の欄の初期値は引戻した承認の指定(合議 = u0009・u0010・u0016、責任者 = u0003。差戻しの後と同じ = 処理対象者.md 5.2。
     * 画面の初期値だけで、計画の今の指定は変えない)。
     */
    public function test_the_designator_sees_the_pulled_back_assignments_as_the_initial_values(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->approveOnBranch($case, 'u0002', ['council' => ['u0009', 'u0010', 'u0016'], 'owner' => ['u0003']]);
        $this->actingAsUser('u0002');

        $this->post("/workflow/cases/{$case}/pull-back", ['revision' => $this->revisionOfCase($case), 'comment' => null, 'node_id' => $this->nodeIdOf($case, 'section_chief')], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors();
        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('case.currentSummary', ['state' => 'waiting', 'name' => '課長'])
            ->where('nodeActions.0.designations', fn (Collection $designations) => $designations->map(fn (array $row) => [$row['name'], array_column($row['initial'], 'code')])->all() === [
                ['関係部署の合議', ['u0009', 'u0010', 'u0016']], ['責任者', ['u0003']],
            ])
        );
    }

    /**
     * 並列の 2 つのノードの処理対象者(同期の枝 a1・b1 の両方が u0002)で、差戻しできる先があるのは a1 だけ: ノードごとに差戻しの可否と候補が分かれ、b1 の承認は残る
     * (候補と可否を 1 か所で作り、ほかのノードの値で上書きしない)。
     */
    public function test_two_parallel_nodes_of_one_person_have_their_own_send_back(): void
    {
        $this->addVersion([[$this->applyNode(),
            new RouteNodeInput('sync', '同期', WorkflowNodeKind::SyncStart, closingCode: 'sync_end'),
            $this->approveNode('a1', 'u0002', ['apply']), $this->approveNode('b1', 'u0002'),
            new RouteNodeInput('sync_end', '同期終了', WorkflowNodeKind::SyncEnd),
        ], $this->chain(['apply', 'sync'], ['sync', 'a1'], ['sync', 'b1'], ['a1', 'sync_end'], ['b1', 'sync_end'])]);
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->actingAsUser('u0002');

        $this->get("/workflow/cases/{$case->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nodeActions', fn (Collection $actions) => $actions->map(fn (array $action) => [$action['name'], $action['approve'], $action['sendBack'], array_column($action['sendBackTargets'], 'name')])->all() === [
                ['a1', true, true, ['申請']], ['b1', true, false, []],
            ])
        );
    }

    /**
     * 処理対象者が複数(東京営業課 = u0001・u0002)で申請者がその 1 人(モック 6a): 申請者の詳細に blockedReason が出て、承認・承認終了・否認の HTTP が 422 case で
     * 何も変わらない。ほかの処理対象者(u0002)には出ない。差戻しは申請者もできる(候補がある)。
     */
    public function test_the_applicant_among_several_targets_is_blocked_with_the_reason_and_a_422(): void
    {
        $this->addVersion([[$this->applyNode(),
            new RouteNodeInput('sales', '東京営業課', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('tokyo-sales'))], ['ja' => '東京営業課'], preventsApplicantApproval: true, sendBackCodes: ['apply']),
            $this->approveNode('final', 'u0010', ['apply', 'sales']),
        ], $this->chain(['apply', 'sales'], ['sales', 'final'])]);
        $case = $this->applyAs($this->seededUser('u0001'));
        $sales = $this->nodeIdOf($case->id, 'sales');
        $this->actingAsUser('u0001');

        $this->get("/workflow/cases/{$case->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nodeActions.0.nodeId', $sales)
            ->where('nodeActions.0.blockedReason', self::BLOCKED)
            ->where('nodeActions.0.sendBack', true)
        );

        foreach (['approve', 'approve-early', 'reject'] as $action) {
            $this->post("/workflow/cases/{$case->id}/{$action}", ['revision' => $this->revisionOfCase($case->id), 'comment' => null, 'node_id' => $sales], self::INERTIA)
                ->assertStatus(302)->assertSessionHasErrors(['case' => self::BLOCKED]);
        }

        $this->assertSame([0, 'waiting'], [$this->revisionOfCase($case->id), WorkflowCaseNode::query()->findOrFail($sales)->state->value]);
        $this->actingAsUser('u0002');
        $this->get("/workflow/cases/{$case->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('nodeActions.0.blockedReason', null));
    }

    /**
     * 処理対象者が申請者本人だけになる申請(u0002 が版 3 で申請 = 課長が u0002 だけ): 申請の画面の next の課長に blockedReason が出て(画面.md 5 節)、
     * 送っても HTTP で 422 case、案件はできない。
     */
    public function test_an_application_whose_first_step_is_only_the_applicant_is_rejected(): void
    {
        $this->actingAsUser('u0002');
        $count = WorkflowCase::query()->count();
        $this->get('/workflow/flows/project_plan/apply')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('next.nodes', [['name' => '課長', 'targets' => [['id' => $this->branchUserId('u0002'), 'code' => 'u0002', 'name' => '中村 美咲']], 'blockedReason' => '課長の処理対象者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。']])
        );

        $this->post('/workflow/flows/project_plan/apply', [
            'name' => '', 'comment' => null, 'base_date' => $this->branchToday()->toDateString(),
            'values' => ['name' => '自分の課の計画', 'department' => $this->departmentIdOf('tokyo-sales'), 'manager' => $this->branchUserId('u0002'), 'status' => 'planning', 'start_date' => '2026-11-01', 'budget' => 100],
        ], self::INERTIA)->assertStatus(302)->assertSessionHasErrors(['case' => '課長の処理対象者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。']);
        $this->assertSame($count, WorkflowCase::query()->count());
    }

    private function nodeIdOf(int $caseId, string $code): int
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('code', $code)->sole()->id;
    }

    private function revisionOfCase(int $caseId): int
    {
        return WorkflowCase::query()->findOrFail($caseId)->revision;
    }
}
