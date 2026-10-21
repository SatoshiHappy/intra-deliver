<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowConfirmationProgress;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CaseConfirmations;
use PHPUnit\Framework\TestCase;

/**
 * 確認対象者 1 人の確認の状態の式(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.5 節)の枝のそれぞれ。DB を使わずに確かめる
 * (案件の操作から作る場面は Feature の ConfirmCaseTest)。期待値は 1.5 節の式から書いた。
 */
final class CaseConfirmationsTest extends TestCase
{
    public function test_each_branch_of_the_formula(): void
    {
        $active = WorkflowCaseState::Active;
        $completed = WorkflowCaseState::Completed;
        $approve = WorkflowNodeKind::Approve;
        $apply = WorkflowNodeKind::Apply;
        $done = WorkflowNodeState::Done;
        $unreached = WorkflowNodeState::Unreached;
        $skipped = WorkflowNodeState::Skipped;
        $reapply = WorkflowNodeState::Reapply;
        $waiting = WorkflowNodeState::Waiting;
        $cases = [
            '省略(完了でも)' => [$completed, $approve, $skipped, false, WorkflowConfirmationProgress::Skipped],
            '到達しないまま完了' => [$completed, $approve, $unreached, false, WorkflowConfirmationProgress::NotProcessed],
            '処理済みで今の開きへの確認がある' => [$active, $approve, $done, true, WorkflowConfirmationProgress::Confirmed],
            '処理済みで確認しないまま完了' => [$completed, $approve, $done, false, WorkflowConfirmationProgress::Unconfirmed],
            '処理済みで未確認' => [$active, $apply, $done, false, WorkflowConfirmationProgress::Unconfirmed],
            '申請のノードが再申請待ち' => [$active, $apply, $reapply, false, WorkflowConfirmationProgress::Reapply],
            '承認のノードが承認待ち' => [$active, $approve, $waiting, false, WorkflowConfirmationProgress::Waiting],
            '承認のノードが保留中' => [$active, $approve, WorkflowNodeState::Hold, false, WorkflowConfirmationProgress::Waiting],
            '差戻しで到達していないに戻った' => [$active, $approve, $unreached, true, WorkflowConfirmationProgress::Waiting],
        ];

        foreach ($cases as $label => [$caseState, $kind, $nodeState, $confirmed, $expected]) {
            $case = new WorkflowCase(['state' => $caseState]);
            $node = new WorkflowCaseNode(['kind' => $kind, 'state' => $nodeState]);

            $this->assertSame($expected, CaseConfirmations::stateOf($case, $node, $confirmed), $label);
        }
    }
}
