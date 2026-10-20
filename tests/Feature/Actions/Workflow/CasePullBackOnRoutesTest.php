<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 引戻しの 1 規則と写しからの戻し(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節の引戻しの行・10.5、同時実行と検査.md 14 節の「引戻し」)。
 * 期待値は 10.5 の規則と、同じ 10.5 の並列の枝・合議の票の引戻しの項から書いた。3 つの場面(R1 の課長の承認の引戻し、R2 の申請直後の引戻し、
 * 合議の否認票の引戻し)を含む。各操作の後に不変の条件 5 つを確かめる({@see OperatesOnRoutes})。人は初期データ(申請者 u0001)。
 */
final class CasePullBackOnRoutesTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesOnRoutes;

    private const NOT_PERMITTED = ['case' => 'この申請は今この操作をできません'];

    /**
     * R1 で課長の承認を引戻すと、分岐開始の通過・taken・部長の到達が写しから戻り、課長が承認待ち(処理対象者 = 引戻した人)。承認し直すと部長が到達する。
     * 引戻しの行は node_changes を持たず、引戻した行を指す。
     */
    public function test_pulling_back_the_section_chief_restores_the_branch_and_reapproval_reaches_the_manager(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 500]);
        $approval = $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);

        $result = $this->pullBackOn($case, 'section_chief', 'u0002');

        $this->assertSame(['apply' => 'done', 'section_chief' => 'waiting[u0002]', 'budget' => 'unreached', 'manager' => 'unreached', 'budget_end' => 'unreached', 'council' => 'unreached', 'owner' => 'unreached'], $this->shape($case));
        $this->assertSame([null, null], $this->takenOf($case));
        $this->assertResult($case, $result, WorkflowAction::PullBack, 'u0002', ['section_chief' => ['u0002']], null);
        $row = WorkflowCaseHistory::query()->findOrFail($result->historyId);
        $this->assertSame([null, $approval->historyId], [$row->node_changes, $row->reverted_workflow_case_history_id]);
        $this->assertSame(['u0002' => ['pending', 'processed'], 'u0003' => []], $this->appearances($case, 'u0002', 'u0003'));

        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);

        $this->assertSame(['budget' => 'passed', 'manager' => 'waiting[u0003]'], $this->pick($case, 'budget', 'manager'));
        $this->assertSame([true, false], $this->takenOf($case));
    }

    /**
     * R2 の申請の直後(現在のノード 2 つ)に申請者が引戻すと、同期と両方の枝が戻り、申請のノードが再申請待ち。再申請で両方の枝に到達し直す。
     */
    public function test_pulling_back_the_application_with_two_current_nodes(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);

        $result = $this->pullBackOn($case, 'apply', 'u0001');

        $this->assertSame(['apply' => 'reapply[u0001]', 'sync' => 'unreached', 'a1' => 'unreached', 'a2' => 'unreached', 'b1' => 'unreached', 'sync_end' => 'unreached', 'final' => 'unreached'], $this->shape($case));
        $this->assertResult($case, $result, WorkflowAction::PullBack, 'u0001', ['apply' => ['u0001']], null);

        $this->reapply($this->seededUser('u0001'), $case);

        $this->assertSame(['a1' => 'waiting[u0002]', 'b1' => 'waiting[u0004]'], $this->pick($case, 'a1', 'b1'));
        $this->assertRouteInvariants($case);
    }

    /**
     * 合議の否認票(まだ可決できる)は判定が付く前なので引戻せ、その子が承認待ちに戻る。承認票も同じ。
     */
    public function test_council_votes_can_be_pulled_back_before_the_decision(): void
    {
        $case = $this->caseAtCouncil(['u0004', 'u0005', 'u0009']);
        $this->reject($case, 'council.1', 'u0004');

        $this->pullBackOn($case, 'council.1', 'u0004');

        $this->assertSame(['running', 'waiting[u0004]', 'waiting[u0005]', 'waiting[u0009]'], $this->councilShape($case));

        $this->approve($case, 'council.2', 'u0005');
        $this->pullBackOn($case, 'council.2', 'u0005');

        $this->assertSame(['council' => 'running', 'council.2' => 'waiting[u0005]'], $this->pick($case, 'council', 'council.2'));
    }

    /**
     * 可決を決めた票は、後のノードの処理の前なら引戻せ、合議が再開する(親 = 展開中・残りの子 = 承認待ち・後のノード = 到達していない)。写しから承認待ちに
     * 戻った残りの子も到達したノード(11 節)。それより前の票は、可決の後は 422 case。後のノードが処理した後は、可決を決めた票も 422 case。
     */
    public function test_the_deciding_vote_can_be_pulled_back_until_the_next_node_is_processed(): void
    {
        $case = $this->caseAtCouncil(['u0004', 'u0005', 'u0009']);
        $this->approve($case, 'council.1', 'u0004');
        $this->approve($case, 'council.2', 'u0005');

        $this->assertPullBackRejected($case, 'council.1', 'u0004');

        $result = $this->pullBackOn($case, 'council.2', 'u0005');

        $this->assertSame(['running', 'done', 'waiting[u0005]', 'waiting[u0009]', 'unreached'], [...$this->councilShape($case), $this->shape($case)['owner']]);
        $this->assertResult($case, $result, WorkflowAction::PullBack, 'u0005', ['council.2' => ['u0005'], 'council.3' => ['u0009']], null);

        $this->approve($case, 'council.2', 'u0005');
        $this->approve($case, 'owner', 'u0010');

        $this->assertPullBackRejected($case, 'council.2', 'u0005');
    }

    /**
     * 並列の枝: ほかの枝の処理の後でも、自分の枝に触れられていなければ引戻せる。同期の片方の枝を承認 → もう片方の承認で合流が通過すると、最初の枝の引戻しは
     * 422 case、合流を通過させた最後の枝の承認は引戻せ、合流と後のノードが戻る。
     */
    public function test_sync_branches_are_pulled_back_independently_until_the_join(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'a1', 'u0002');
        $this->approve($case, 'b1', 'u0004');

        $this->pullBackOn($case, 'a1', 'u0002');

        $this->assertSame(['a1' => 'waiting[u0002]', 'a2' => 'unreached', 'b1' => 'done', 'sync_end' => 'unreached'], $this->pick($case, 'a1', 'a2', 'b1', 'sync_end'));

        $this->approve($case, 'a1', 'u0002');
        $this->approve($case, 'a2', 'u0003');
        $this->assertSame(['sync_end' => 'passed', 'final' => 'waiting[u0010]'], $this->pick($case, 'sync_end', 'final'));

        $this->assertPullBackRejected($case, 'b1', 'u0004');

        $this->pullBackOn($case, 'a2', 'u0003');

        $this->assertSame(['a2' => 'waiting[u0003]', 'b1' => 'done', 'sync_end' => 'unreached', 'final' => 'unreached'], $this->pick($case, 'a2', 'b1', 'sync_end', 'final'));
    }

    /**
     * 分岐終了を通過させた最後の承認(部長)は、後のノード(合議)の処理の前なら引戻せ、分岐終了と合議(親と子)が戻る。
     */
    public function test_the_approval_that_passed_the_branch_end_can_be_pulled_back(): void
    {
        $case = $this->caseAtCouncil(['u0004', 'u0005']);

        $this->pullBackOn($case, 'manager', 'u0003');

        $this->assertSame(['manager' => 'waiting[u0003]', 'budget_end' => 'unreached', 'council' => 'unreached', 'council.1' => 'unreached', 'council.2' => 'unreached'], $this->pick($case, 'manager', 'budget_end', 'council', 'council.1', 'council.2'));
    }

    /**
     * 引戻せるノードが 2 つある人は node_id で選ぶ(選んだノードだけが戻る)。引戻せないノードの node_id は 422 case で何も変わらない。
     */
    public function test_a_person_with_two_pullbackable_nodes_chooses_one_by_node_id(): void
    {
        $this->addVersion($this->sameApproverOnBothBranches());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'a1', 'u0002');
        $this->approve($case, 'b1', 'u0002');

        $this->assertPullBackRejected($case, 'apply', 'u0002');
        $this->pullBackOn($case, 'b1', 'u0002');

        $this->assertSame(['a1' => 'done', 'a2' => 'waiting[u0003]', 'b1' => 'waiting[u0002]'], $this->pick($case, 'a1', 'a2', 'b1'));
    }

    /**
     * 横配置の子の承認は、次の子の処理の前なら引戻せる(次の子 = 写しから到達していない、引戻した子 = 承認待ち、親 = 展開中のまま)。
     */
    public function test_a_serial_child_approval_can_be_pulled_back_before_the_next_child_processes(): void
    {
        $this->addVersion($this->r3());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['line' => ['u0004', 'u0005', 'u0009']]);
        $this->approve($case, 'line.1', 'u0004');

        $result = $this->pullBackOn($case, 'line.1', 'u0004');

        $this->assertSame(['running', 'waiting[u0004]', 'unreached', 'unreached', 'unreached'], [...$this->lineShape($case), $this->shape($case)['final']]);
        $this->assertResult($case, $result, WorkflowAction::PullBack, 'u0004', ['line.1' => ['u0004']], null);
        $this->assertSame(['u0004' => ['pending', 'processed'], 'u0005' => []], $this->appearances($case, 'u0004', 'u0005'));
    }

    /**
     * 指定 0 人で横配置を省いた承認(chief)を引戻すと、省いた横配置と到達させた後のノードが写しから到達していないに戻り、chief が承認待ち(処理対象者 = 引戻した人)。
     */
    public function test_pulling_back_the_approval_that_skipped_a_node_with_zero_assignees(): void
    {
        $this->addVersion($this->r3(minAssignees: 0));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['line' => []]);
        $this->assertSame(['line' => 'skipped', 'final' => 'waiting[u0010]'], $this->pick($case, 'line', 'final'));

        $result = $this->pullBackOn($case, 'chief', 'u0002');

        $this->assertSame(['apply' => 'done', 'chief' => 'waiting[u0002]', 'line' => 'unreached', 'final' => 'unreached'], $this->shape($case));
        $this->assertResult($case, $result, WorkflowAction::PullBack, 'u0002', ['chief' => ['u0002']], null);
        $this->assertSame(['u0002' => ['pending', 'processed'], 'u0010' => ['referenced']], $this->appearances($case, 'u0002', 'u0010'));
    }

    /**
     * R1 の合議(人数 2、指定 = $council)まで進めた案件(予算 ≥ 300 = 部長を通る)。
     *
     * @param  list<string>  $council
     */
    private function caseAtCouncil(array $council): WorkflowCase
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 500]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => $council, 'owner' => ['u0010']]);
        $this->approve($case, 'manager', 'u0003');

        return $case;
    }

    private function assertPullBackRejected(WorkflowCase $case, string $label, string $userCode): void
    {
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];

        $errors = $this->validationErrors(fn () => app(PullBackCase::class)->execute($this->contextOf($this->seededUser($userCode)), $case->id, $this->revisionOf($case), $this->labelIds($case)[$label], null));

        $this->assertSame(self::NOT_PERMITTED, $errors, "{$userCode} が {$label} を引戻せた");
        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * @return list<bool|null> 分岐「予算」から出る辺の taken(並びの順)
     */
    private function takenOf(WorkflowCase $case): array
    {
        return array_values(WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->where('from_workflow_case_node_id', $this->labelIds($case)['budget'])->orderBy('sort_key')->get()->map(fn (WorkflowCaseEdge $edge) => $edge->taken)->all());
    }

    /**
     * R2 の枝 B の承認(b1)も u0002 にした形(u0002 が両方の枝を処理できる)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function sameApproverOnBothBranches(): array
    {
        [$nodes, $edges] = $this->r2();

        return [array_map(fn (RouteNodeInput $node) => $node->code === 'b1' ? $this->approveNode('b1', 'u0002', ['apply']) : $node, $nodes), $edges];
    }
}
