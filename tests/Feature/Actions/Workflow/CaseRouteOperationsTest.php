<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CasePlanRequest;
use App\Actions\Workflow\CaseRoutePlanner;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use LogicException;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 申請・承認の系統を計画に結んだ業務処理の状態 × 操作の表(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節の申請・承認(普通・横配置の子・縦配置の子)・
 * 承認終了・否認の行。同時実行と検査.md 14 節)。期待値は 8 節の表の「結果」「一覧への出方」の列と 11 節の操作の結果の値から書いた。各操作の後に不変の条件 5 つと
 * 指定の行の履歴を確かめる。ルートの見本 R1〜R3 と操作の手助けは {@see OperatesOnRoutes}(版を足し、本物の申請の業務処理で写す)。人は初期データ(申請者 u0001)。
 */
final class CaseRouteOperationsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesOnRoutes;

    public function test_applying_on_r1_reaches_the_section_chief_and_the_applicant_sees_it_as_processed(): void
    {
        $this->addVersion($this->r1());

        [$case, $result] = $this->applyOnRoute(['budget' => 500]);

        $this->assertSame(['apply' => 'done', 'section_chief' => 'waiting[u0002]', 'budget' => 'unreached', 'manager' => 'unreached', 'budget_end' => 'unreached', 'council' => 'unreached', 'owner' => 'unreached'], $this->shape($case));
        $this->assertResult($case, $result, WorkflowAction::Apply, 'u0001', ['section_chief' => ['u0002']], null);
        $this->assertSame(['u0001' => ['processed'], 'u0002' => ['pending']], $this->appearances($case, 'u0001', 'u0002'));
        $this->assertSame(WorkflowCaseState::Active, $case->refresh()->state);
    }

    /**
     * 指定するノード(課長)の承認: 指定の行を承認の履歴の行で書き、分岐で部長へ(予算 ≥ 300)。部長の承認で縦配置を展開し、3 人の子が同時に承認待ち(合議の 3 人の未処理に出る)。
     */
    public function test_approving_the_designator_writes_the_assignments_and_the_council_reaches_all_children(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 500]);

        $chief = $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005', 'u0006'], 'owner' => ['u0010']]);

        $this->assertSame(['apply' => 'done', 'section_chief' => 'done', 'budget' => 'passed', 'manager' => 'waiting[u0003]', 'budget_end' => 'unreached', 'council' => 'unreached', 'owner' => 'unreached'], $this->shape($case));
        $this->assertResult($case, $chief, WorkflowAction::Approve, 'u0002', ['manager' => ['u0003']], null);
        $this->assertSame([[$chief->historyId, 'council', 1, 'u0004'], [$chief->historyId, 'council', 2, 'u0005'], [$chief->historyId, 'council', 3, 'u0006'], [$chief->historyId, 'owner', 1, 'u0010']], $this->assignmentRows($case));
        $this->assertSame([true, false], WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->where('from_workflow_case_node_id', $this->labelIds($case)['budget'])->orderBy('sort_key')->pluck('taken')->all());

        $manager = $this->approve($case, 'manager', 'u0003');

        $this->assertSame(['running', 'waiting[u0004]', 'waiting[u0005]', 'waiting[u0006]'], $this->councilShape($case));
        $this->assertResult($case, $manager, WorkflowAction::Approve, 'u0003', ['council.1' => ['u0004'], 'council.2' => ['u0005'], 'council.3' => ['u0006']], null);
        $this->assertSame(['u0003' => ['processed'], 'u0004' => ['pending'], 'u0005' => ['pending'], 'u0006' => ['pending']], $this->appearances($case, 'u0003', 'u0004', 'u0005', 'u0006'));
    }

    /**
     * 合議の票(人数 2): 1 票目は未決(処理者の未処理から消えるだけ)、2 票目で可決 → 残りの子を省き、親 = 通過、責任者へ。責任者の承認で完了(承認)。
     */
    public function test_council_votes_pass_with_the_needed_count_then_the_owner_completes_the_case(): void
    {
        $case = $this->caseAtCouncil(['u0004', 'u0005', 'u0006']);

        $first = $this->approve($case, 'council.1', 'u0004');

        $this->assertSame(['running', 'done', 'waiting[u0005]', 'waiting[u0006]', 'unreached'], [...$this->councilShape($case), $this->shape($case)['owner']]);
        $this->assertResult($case, $first, WorkflowAction::Approve, 'u0004', [], null);
        $this->assertSame(['u0004' => ['processed'], 'u0005' => ['pending'], 'u0006' => ['pending']], $this->appearances($case, 'u0004', 'u0005', 'u0006'));

        $second = $this->approve($case, 'council.2', 'u0005');

        $this->assertSame(['passed', 'done', 'done', 'skipped', 'waiting[u0010]'], [...$this->councilShape($case), $this->shape($case)['owner']]);
        $this->assertResult($case, $second, WorkflowAction::Approve, 'u0005', ['owner' => ['u0010']], null);
        $this->assertSame(['u0006' => [], 'u0010' => ['pending', 'referenced']], $this->appearances($case, 'u0006', 'u0010'));

        $owner = $this->approve($case, 'owner', 'u0010');

        $this->assertSame([WorkflowCaseState::Completed, WorkflowCompletionReason::Approved], [$case->refresh()->state, $case->completion_reason]);
        $this->assertResult($case, $owner, WorkflowAction::Approve, 'u0010', [], WorkflowCompletionReason::Approved);
        $this->assertSame(['u0001' => ['processed'], 'u0010' => ['processed', 'referenced']], $this->appearances($case, 'u0001', 'u0010'));
    }

    /**
     * 合議の否認票: まだ可決できるうちは未決で待ち(否認した子は処理済み(否認))、可決できなくなった票で完了(否認)と完了の片付け(残りの子・親を省く)。
     */
    public function test_council_rejections_wait_while_passable_and_complete_as_rejected_when_not(): void
    {
        $case = $this->caseAtCouncil(['u0004', 'u0005', 'u0006']);

        $first = $this->reject($case, 'council.1', 'u0004');

        $this->assertSame(['running', 'done', 'waiting[u0005]', 'waiting[u0006]'], $this->councilShape($case));
        $this->assertResult($case, $first, WorkflowAction::Reject, 'u0004', [], null);

        $second = $this->reject($case, 'council.2', 'u0005');

        $this->assertSame(['skipped', 'done', 'done', 'skipped'], $this->councilShape($case));
        $this->assertSame([WorkflowCaseState::Completed, WorkflowCompletionReason::Rejected], [$case->refresh()->state, $case->completion_reason]);
        $this->assertResult($case, $second, WorkflowAction::Reject, 'u0005', [], WorkflowCompletionReason::Rejected);
        $this->assertSame(['u0006' => [], 'u0001' => ['processed']], $this->appearances($case, 'u0006', 'u0001'));
    }

    /**
     * 承認終了は合議の中でも案件を完了する(完了の片付け: ほかの現在のノード(残りの子)と展開中の親を省く。後のノードは到達していないのまま)。
     */
    public function test_approving_early_in_the_council_completes_the_case_and_cleans_up(): void
    {
        $case = $this->caseAtCouncil(['u0004', 'u0005', 'u0006']);

        $result = $this->operateOn(ApproveCaseEarly::class, $case, 'council.2', 'u0005');

        $this->assertSame(['skipped', 'skipped', 'done', 'skipped', 'unreached'], [...$this->councilShape($case), $this->shape($case)['owner']]);
        $this->assertSame([WorkflowCaseState::Completed, WorkflowCompletionReason::ApprovedEarly], [$case->refresh()->state, $case->completion_reason]);
        $this->assertResult($case, $result, WorkflowAction::ApproveEarly, 'u0005', [], WorkflowCompletionReason::ApprovedEarly);
        $this->assertSame(['u0004' => [], 'u0006' => []], $this->appearances($case, 'u0004', 'u0006'));
    }

    /**
     * 予算 < 300 なら分岐で部長の枝を省き(偽の区間 = 省略)、課長の承認で合議へ直接進む。
     */
    public function test_a_false_branch_is_skipped_and_the_council_follows_the_section_chief(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);

        $result = $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);

        $this->assertSame(['skipped', 'passed', 'running', 'waiting[u0004]', 'waiting[u0005]'], [$this->shape($case)['manager'], $this->shape($case)['budget_end'], ...$this->councilShape($case)]);
        $this->assertResult($case, $result, WorkflowAction::Approve, 'u0002', ['council.1' => ['u0004'], 'council.2' => ['u0005']], null);
    }

    /**
     * 同期(R2): 申請で 2 つの枝の承認が同時に承認待ち。片方の承認はほかの枝を変えず、両方の枝が済むと同期終了を通過して最後のノードへ。
     */
    public function test_sync_branches_are_processed_independently_and_join_when_both_are_done(): void
    {
        $this->addVersion($this->r2());
        [$case, $applied] = $this->applyOnRoute([]);

        $this->assertSame(['a1' => 'waiting[u0002]', 'a2' => 'unreached', 'b1' => 'waiting[u0004]', 'sync_end' => 'unreached', 'final' => 'unreached'], $this->pick($case, 'a1', 'a2', 'b1', 'sync_end', 'final'));
        $this->assertResult($case, $applied, WorkflowAction::Apply, 'u0001', ['a1' => ['u0002'], 'b1' => ['u0004']], null);

        $this->approve($case, 'b1', 'u0004');
        $this->assertSame(['a1' => 'waiting[u0002]', 'a2' => 'unreached', 'b1' => 'done', 'sync_end' => 'unreached', 'final' => 'unreached'], $this->pick($case, 'a1', 'a2', 'b1', 'sync_end', 'final'));
        $this->assertSame(['u0002' => ['pending'], 'u0004' => ['processed']], $this->appearances($case, 'u0002', 'u0004'));

        $this->approve($case, 'a1', 'u0002');
        $last = $this->approve($case, 'a2', 'u0003');

        $this->assertSame(['a1' => 'done', 'a2' => 'done', 'b1' => 'done', 'sync_end' => 'passed', 'final' => 'waiting[u0010]'], $this->pick($case, 'a1', 'a2', 'b1', 'sync_end', 'final'));
        $this->assertResult($case, $last, WorkflowAction::Approve, 'u0003', ['final' => ['u0010']], null);
    }

    /**
     * 横配置(R3): 子 1 から順に到達し(次の子の人の未処理に出る)、最後の子の承認で親が通過して次のノードへ。
     */
    public function test_serial_children_are_reached_in_order_and_the_last_one_passes_the_parent(): void
    {
        $case = $this->caseAtLine(['u0004', 'u0005']);

        $this->assertSame(['running', 'waiting[u0004]', 'unreached'], $this->lineShape($case));

        $first = $this->approve($case, 'line.1', 'u0004');

        $this->assertSame(['running', 'done', 'waiting[u0005]'], $this->lineShape($case));
        $this->assertResult($case, $first, WorkflowAction::Approve, 'u0004', ['line.2' => ['u0005']], null);
        $this->assertSame(['u0004' => ['processed'], 'u0005' => ['pending']], $this->appearances($case, 'u0004', 'u0005'));

        $second = $this->approve($case, 'line.2', 'u0005');

        $this->assertSame(['passed', 'done', 'done', 'waiting[u0010]'], [...$this->lineShape($case), $this->shape($case)['final']]);
        $this->assertResult($case, $second, WorkflowAction::Approve, 'u0005', ['final' => ['u0010']], null);
    }

    public function test_rejecting_a_serial_child_completes_the_case_as_rejected(): void
    {
        $case = $this->caseAtLine(['u0004', 'u0005']);

        $result = $this->reject($case, 'line.1', 'u0004');

        $this->assertSame(['skipped', 'done', 'unreached'], $this->lineShape($case));
        $this->assertSame([WorkflowCaseState::Completed, WorkflowCompletionReason::Rejected], [$case->refresh()->state, $case->completion_reason]);
        $this->assertResult($case, $result, WorkflowAction::Reject, 'u0004', [], WorkflowCompletionReason::Rejected);
    }

    /**
     * 横配置の子の承認終了は案件を完了する(完了の片付け = 展開中の親を省く。到達していない後の子と後のノードはそのまま = 8 節の承認終了の行・2.6)。
     */
    public function test_approving_early_a_serial_child_completes_the_case_and_skips_the_parent(): void
    {
        $case = $this->caseAtLine(['u0004', 'u0005', 'u0006']);
        $this->approve($case, 'line.1', 'u0004');

        $result = $this->operateOn(ApproveCaseEarly::class, $case, 'line.2', 'u0005');

        $this->assertSame(['skipped', 'done', 'done', 'unreached', 'unreached'], [...$this->lineShape($case), $this->shape($case)['final']]);
        $this->assertSame([WorkflowCaseState::Completed, WorkflowCompletionReason::ApprovedEarly], [$case->refresh()->state, $case->completion_reason]);
        $this->assertResult($case, $result, WorkflowAction::ApproveEarly, 'u0005', [], WorkflowCompletionReason::ApprovedEarly);
        $this->assertSame(['u0005' => ['processed'], 'u0006' => []], $this->appearances($case, 'u0005', 'u0006'));
    }

    /**
     * 並列の枝のほかの人は、自分のノード以外を操作できない(node_id が自分の処理対象者でないノード・この案件のノードでない・現在のノードでない = 422 case、何も変わらない)。
     */
    public function test_a_node_that_is_not_mine_or_not_current_or_not_of_the_case_is_rejected(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        [$other] = $this->applyOnRoute([]);
        $ids = $this->labelIds($case);
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
        $approve = fn (int $nodeId) => $this->validationErrors(fn () => app(ApproveCase::class)->execute($this->contextOf($this->seededUser('u0002')), $case->id, $this->revisionOf($case), $nodeId, null));

        foreach ([$ids['b1'], $ids['a2'], $ids['apply'], $this->labelIds($other)['a1'], 999_999_999] as $nodeId) {
            $this->assertSame(['case' => 'この申請は今この操作をできません'], $approve($nodeId));
        }

        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * 案件の辺を写す作りより前に作った案件は、辺の行を持たない。ノードが 2 つ以上あるのに辺が 0 本の案件は、計画が LogicException で拒む(最初の承認で黙って完了させない)。
     */
    public function test_a_case_without_copied_edges_is_refused_by_the_planner(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->delete();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('辺を写していない');

        app(CaseRoutePlanner::class)->plan(new CasePlanRequest($case->refresh(), $this->labelIds($case)['b1'], WorkflowAction::Approve, $this->seededUser('u0004')->id, null));
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

    /**
     * R3 の横配置(指定 = $line)まで進めた案件。
     *
     * @param  list<string>  $line
     */
    private function caseAtLine(array $line): WorkflowCase
    {
        $this->addVersion($this->r3());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['line' => $line]);

        return $case;
    }
}
