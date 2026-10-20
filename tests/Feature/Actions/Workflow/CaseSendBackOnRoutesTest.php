<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\SendBackCase;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\UserTerm;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\SendBackCandidates;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 差戻しを計画に結んだ業務処理(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節の差戻しの行・10.4、処理対象者.md 4.5、同時実行と検査.md 13 節・
 * 14 節の「差戻し」)。期待値は 8 節の表・10.4 の候補と結果・13 節の文言から書いた。各操作の後に不変の条件 5 つを確かめる({@see OperatesOnRoutes})。
 * 人は初期データ(申請者 u0001)。
 */
final class CaseSendBackOnRoutesTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesOnRoutes;

    /**
     * 候補 = 祖先の処理済み・通過だけ(並列のほかの枝の処理済み・後のノードは出ない)と、差戻しできる先の積。並び = 写した並び。
     */
    public function test_candidates_are_the_settled_ancestors_within_the_allowed_targets(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'a1', 'u0002');

        $this->assertSame(['apply', 'a1'], $this->candidates($case, 'a2'));
        $this->assertSame(['apply'], $this->candidates($case, 'b1'));

        $this->approve($case, 'b1', 'u0004');
        $this->approve($case, 'a2', 'u0003');

        $this->assertSame(['apply', 'a1', 'a2', 'b1'], $this->candidates($case, 'final'));
    }

    /**
     * 差戻しできる先がどれも祖先の候補に無い(通らなかった枝の部長だけを差戻しできる先にした)なら、候補 = 全部の祖先の候補(本家 RB-2)。省略の部長と分岐は入らない。
     */
    public function test_candidates_fall_back_to_all_settled_ancestors_when_no_allowed_target_is_one(): void
    {
        $this->addVersion($this->r1WithCouncilSendBacks(['manager']));
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);

        $this->assertSame(['apply', 'section_chief'], $this->candidates($case, 'council.1'));
    }

    /**
     * 差戻しできる先が 1 つも無いステップでは候補が無く、差戻しは 422 send_back_node_id(13 節)で何も変わらない。詳細は差戻しのボタンを出さない(画面.md 6 節)。
     */
    public function test_a_node_without_allowed_targets_cannot_send_back(): void
    {
        $this->addVersion($this->r1WithCouncilSendBacks([]));
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
        $ids = $this->labelIds($case);

        $errors = $this->validationErrors(fn () => app(SendBackCase::class)->execute($this->contextOf($this->seededUser('u0004')), $case->id, $this->revisionOf($case), $ids['council.1'], $ids['apply'], null));

        $this->assertSame(['send_back_node_id' => 'このステップでは差戻しできません'], $errors);
        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
        $detail = app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0004')), $case->id);
        $this->assertSame([[$ids['council.1'], false, []]], array_map(fn (array $action) => [$action['nodeId'], $action['sendBack'], $action['sendBackTargets']], $detail['nodeActions']));
    }

    /**
     * 合議の子から分岐の前(課長)へ差戻すと、課長の子孫(分岐・省略の部長・分岐終了・合議の親と子・責任者)が到達していないに戻り、taken は NULL に戻る。
     * 課長は以前の処理者で承認待ち。課長が承認し直すと、分岐を評価し直して部長を省き、合議を新しい指定で展開する(多い番号の子は省く)。
     */
    public function test_sending_back_before_a_branch_resets_the_section_and_the_branch_is_evaluated_again(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005', 'u0009'], 'owner' => ['u0010']]);
        $ids = $this->labelIds($case);

        $result = $this->sendBackOn($case, 'council.1', 'u0004', 'section_chief');

        $this->assertSame(
            ['apply' => 'done', 'section_chief' => 'waiting[u0002]', 'budget' => 'unreached', 'manager' => 'unreached', 'budget_end' => 'unreached', 'council' => 'unreached', 'council.1' => 'unreached', 'council.2' => 'unreached', 'council.3' => 'unreached', 'owner' => 'unreached'],
            $this->shape($case),
        );
        $this->assertSame([null, null], $this->takenOf($case));
        $this->assertResult($case, $result, WorkflowAction::SendBack, 'u0004', ['section_chief' => ['u0002']], null);
        $history = WorkflowCaseHistory::query()->findOrFail($result->historyId);
        $this->assertSame([$ids['council.1'], $ids['section_chief']], [$history->workflow_case_node_id, $history->send_back_workflow_case_node_id]);
        $labels = array_flip($ids);
        $this->assertEquals(
            ['section_chief' => 'done', 'budget' => 'passed', 'manager' => 'skipped', 'budget_end' => 'passed', 'council' => 'running', 'council.2' => 'waiting', 'council.3' => 'waiting'],
            array_column(array_map(fn (array $change) => [$labels[$change['node']], $change['state']], $history->node_changes ?? []), 1, 0),
        );
        $this->assertSame(['u0002' => ['pending', 'processed'], 'u0004' => ['processed'], 'u0005' => []], $this->appearances($case, 'u0002', 'u0004', 'u0005'));

        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);

        $this->assertSame(['budget' => 'passed', 'manager' => 'skipped', 'budget_end' => 'passed', 'council' => 'running', 'council.1' => 'waiting[u0004]', 'council.2' => 'waiting[u0005]', 'council.3' => 'skipped'], $this->pick($case, 'budget', 'manager', 'budget_end', 'council', 'council.1', 'council.2', 'council.3'));
        $this->assertSame([false, true], $this->takenOf($case));
    }

    /**
     * 差戻し先を以前処理した人が申請基準日に有効でなければ 422 case(13 節。戻す相手は申請基準日で確かめる = 4.5)で何も書かない。
     */
    public function test_a_previous_processor_not_effective_on_the_base_date_is_rejected(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);
        UserTerm::query()->where('user_id', $this->seededUser('u0002')->id)->update(['end_date' => '2026-10-01']);
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
        $ids = $this->labelIds($case);

        $errors = $this->validationErrors(fn () => app(SendBackCase::class)->execute($this->contextOf($this->seededUser('u0004')), $case->id, $this->revisionOf($case), $ids['council.1'], $ids['section_chief'], null));

        $this->assertSame(['case' => '課長の処理対象者がいないため、この操作はできません'], $errors);
        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * 縦配置の親へ差戻すと、親と子が戻ってから展開され、子ごとに以前の処理者が承認待ち(可決で省いた子は今の指定の同じ番号の人)。
     */
    public function test_sending_back_to_a_parallel_parent_reaches_each_child_with_its_previous_processor(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0004', 'u0005', 'u0009']]);
        $this->approve($case, 'family.1', 'u0004');
        $this->approve($case, 'family.2', 'u0005');
        $this->assertSame(['passed', 'done', 'done', 'skipped', 'waiting[u0010]'], [...$this->familyShape($case, 'family'), $this->shape($case)['final']]);

        $result = $this->sendBackOn($case, 'final', 'u0010', 'family');

        $this->assertSame(['running', 'waiting[u0004]', 'waiting[u0005]', 'waiting[u0009]', 'unreached'], [...$this->familyShape($case, 'family'), $this->shape($case)['final']]);
        $this->assertResult($case, $result, WorkflowAction::SendBack, 'u0010', ['family.1' => ['u0004'], 'family.2' => ['u0005'], 'family.3' => ['u0009']], null);
    }

    /**
     * 横配置の親へ差戻すと、子 1 だけが以前の処理者で承認待ちになり、子 1 の承認の後に子 2 が到達する。
     */
    public function test_sending_back_to_a_serial_parent_reaches_the_first_child_with_its_previous_processor(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Serial));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0004', 'u0005']]);
        $this->approve($case, 'family.1', 'u0004');
        $this->approve($case, 'family.2', 'u0005');

        $this->sendBackOn($case, 'final', 'u0010', 'family');

        $this->assertSame(['running', 'waiting[u0004]', 'unreached', 'unreached'], [...$this->familyShape($case, 'family'), $this->shape($case)['final']]);

        $this->approve($case, 'family.1', 'u0004');

        $this->assertSame(['running', 'done', 'waiting[u0005]'], $this->familyShape($case, 'family'));
    }

    /**
     * 横配置の子から申請へ差戻すと、展開中の親と子(処理済みの子 1・差戻し元の子 2・到達していない子 3)と後のノードが到達していないに戻る(申請の子孫 = 10.4)。
     */
    public function test_sending_back_from_a_serial_child_resets_the_parent_and_its_children(): void
    {
        $this->addVersion($this->r3());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['line' => ['u0004', 'u0005', 'u0009']]);
        $this->approve($case, 'line.1', 'u0004');

        $result = $this->sendBackOn($case, 'line.2', 'u0005', 'apply');

        $this->assertSame(['apply' => 'reapply[u0001]', 'chief' => 'unreached', 'line' => 'unreached', 'line.1' => 'unreached', 'line.2' => 'unreached', 'line.3' => 'unreached', 'final' => 'unreached'], $this->shape($case));
        $this->assertResult($case, $result, WorkflowAction::SendBack, 'u0005', ['apply' => ['u0001']], null);
        $this->assertSame(['u0001' => ['pending', 'processed'], 'u0005' => ['processed']], $this->appearances($case, 'u0001', 'u0005'));
    }

    /**
     * 同期の 1 つの枝の中で差戻すと、ほかの枝(差戻し先の子孫でない)は変わらない。差戻し元は到達していない。
     */
    public function test_sending_back_within_one_sync_branch_leaves_the_other_branch(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'a1', 'u0002');

        $this->sendBackOn($case, 'a2', 'u0003', 'a1');

        $this->assertSame(['apply' => 'done', 'sync' => 'passed', 'a1' => 'waiting[u0002]', 'a2' => 'unreached', 'b1' => 'waiting[u0004]', 'sync_end' => 'unreached', 'final' => 'unreached'], $this->shape($case));
    }

    /**
     * 同期の外(申請)へ差戻すと、両方の枝の現在のノードも戻り、申請のノードだけが現在のノード(取止めの前提 = 10.6)。
     */
    public function test_sending_back_to_the_apply_node_resets_both_sync_branches(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'a1', 'u0002');

        $this->sendBackOn($case, 'b1', 'u0004', 'apply');

        $this->assertSame(['apply' => 'reapply[u0001]', 'sync' => 'unreached', 'a1' => 'unreached', 'a2' => 'unreached', 'b1' => 'unreached', 'sync_end' => 'unreached', 'final' => 'unreached'], $this->shape($case));
    }

    /**
     * @return list<string> 差戻しの候補の名札
     */
    private function candidates(WorkflowCase $case, string $label): array
    {
        $labels = array_flip($this->labelIds($case));

        return array_map(fn (WorkflowCaseNode $node) => $labels[$node->id], app(SendBackCandidates::class)->of($this->labelIds($case)[$label]));
    }

    /**
     * @return list<bool|null> 分岐「予算」から出る辺の taken(並びの順)
     */
    private function takenOf(WorkflowCase $case): array
    {
        return array_values(WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->where('from_workflow_case_node_id', $this->labelIds($case)['budget'])->orderBy('sort_key')->get()->map(fn (WorkflowCaseEdge $edge) => $edge->taken)->all());
    }

    /**
     * R1 の合議の差戻しできる先だけを替えた形。
     *
     * @param  list<string>  $sendBackCodes
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function r1WithCouncilSendBacks(array $sendBackCodes): array
    {
        [$nodes, $edges] = $this->r1();
        $council = new RouteNodeInput('council', '関係部署の合議', WorkflowNodeKind::Parallel, WorkflowTargetKind::Designated, designatorCode: 'section_chief', minAssignees: 2, maxAssignees: 5, decisionRule: WorkflowDecisionRule::Count, decisionValue: 2, preventsApplicantApproval: true, sendBackCodes: $sendBackCodes);

        return [array_map(fn (RouteNodeInput $node) => $node->code === 'council' ? $council : $node, $nodes), $edges];
    }
}
