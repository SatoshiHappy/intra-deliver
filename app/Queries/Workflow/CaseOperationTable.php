<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowOperation;
use Closure;

/**
 * 案件の状態 × 操作の表(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節。元の表 = 設計/ワークフロー/案件の操作/操作.md 3.2)。
 * 「案件の状態 × 操作するノード × 役割」だけを見る純粋な関数で、DB を読まず、入口の 403 の判定を含まない。
 * 画面の can と操作の業務処理は、同じ {@see self::permits()} を引く(引く側は {@see CaseOperationDecider})。保留と代理で足した行は
 * 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節、案件の管理者の行は 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.2 節
 * (指定の変更の指定されるノードの前提は、現在のノードでないので表の外 = 業務処理)。
 */
class CaseOperationTable
{
    /** 表で許されないときの文言(業務処理の 422 case = CaseOperationErrors::NOT_PERMITTED はこれを参照する) */
    public const NOT_PERMITTED_MESSAGE = 'この申請は今この操作をできません';

    /**
     * 表に無い組は偽。
     *
     * @param  WorkflowCaseState|null  $state  案件の状態(null = 案件が無い)
     * @param  WorkflowNodeKind|null  $currentKind  現在のノードの種類(現在のノードが無ければ null)
     * @param  WorkflowNodeState|null  $currentState  現在のノードの状態(承認待ち・再申請待ち・保留。無ければ null)
     */
    public function permits(WorkflowOperation $operation, ?WorkflowCaseState $state, ?WorkflowNodeKind $currentKind, ?WorkflowNodeState $currentState, CaseRoles $roles): bool
    {
        $at = function (WorkflowNodeKind $kind, WorkflowNodeState $node) use ($state, $currentKind, $currentState) {
            return $this->isActiveAt($state, $currentKind, $currentState, $kind, $node);
        };

        return match ($operation) {
            WorkflowOperation::SaveDraft => $state === null,
            WorkflowOperation::OverwriteDraft, WorkflowOperation::DeleteDraft => $this->isOwnDraft($state, $roles),
            WorkflowOperation::Apply => $state === null || $this->isOwnDraft($state, $roles),
            WorkflowOperation::Approve, WorkflowOperation::ApproveEarly, WorkflowOperation::Reject,
            WorkflowOperation::SendBack, WorkflowOperation::Transfer => $this->handlesApproval($at, $roles),
            WorkflowOperation::Hold => $roles->isCurrentTarget
                && $at(WorkflowNodeKind::Approve, WorkflowNodeState::Waiting),
            WorkflowOperation::Release => ($roles->isHoldSide || $roles->isCaseManager)
                && $at(WorkflowNodeKind::Approve, WorkflowNodeState::Hold),
            WorkflowOperation::PullBack => $state === WorkflowCaseState::Active && $roles->isPullBackActor,
            // 再申請・取止めの「誰が」= 申請者(申請のノードの処理対象者は申請者 = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
            WorkflowOperation::Reapply, WorkflowOperation::Withdraw => $roles->isApplicant
                && $at(WorkflowNodeKind::Apply, WorkflowNodeState::Reapply),
            WorkflowOperation::Confirm => $state === WorkflowCaseState::Active && $roles->isConfirmTarget,
            WorkflowOperation::Retarget, WorkflowOperation::Reexpand => $roles->isCaseManager
                && $this->isApprovalAt($at),
            // 3 つの移動の前提は同じ(戻り先の候補と指定するノードは表の外 = 業務処理)
            WorkflowOperation::MoveBack, WorkflowOperation::MoveForward,
            WorkflowOperation::MoveFinish => $this->movesAt($state, $currentKind, $roles),
            WorkflowOperation::Reassign => $state === WorkflowCaseState::Active && $roles->isCaseManager,
            WorkflowOperation::DeleteCase => $roles->isCaseManager
                && in_array($state, [WorkflowCaseState::Active, WorkflowCaseState::Completed], true),
        };
    }

    /**
     * 案件の管理者のステップの移動: 未完了で、操作するノードが現在のノード(種類と状態を問わない = 案件操作.md の 15.2 節)。
     */
    private function movesAt(?WorkflowCaseState $state, ?WorkflowNodeKind $currentKind, CaseRoles $roles): bool
    {
        return $roles->isCaseManager && $state === WorkflowCaseState::Active && $currentKind !== null;
    }

    /**
     * 未完了で、操作するノードが承認待ちか保留の承認のノード(展開の行を含む)。
     *
     * @param  Closure(WorkflowNodeKind, WorkflowNodeState): bool  $at  未完了で現在のノードがその種類・状態か
     */
    private function isApprovalAt(Closure $at): bool
    {
        return $at(WorkflowNodeKind::Approve, WorkflowNodeState::Waiting)
            || $at(WorkflowNodeKind::Approve, WorkflowNodeState::Hold);
    }

    /**
     * 承認のノードを処理する行(承認待ちと保留中の「誰が」= 操作.md の 8 節)。
     *
     * @param  Closure(WorkflowNodeKind, WorkflowNodeState): bool  $at  未完了で現在のノードがその種類・状態か
     */
    private function handlesApproval(Closure $at, CaseRoles $roles): bool
    {
        $isWaitingTarget = $roles->isCurrentTarget && $at(WorkflowNodeKind::Approve, WorkflowNodeState::Waiting);

        return $isWaitingTarget || ($roles->isHoldSide && $at(WorkflowNodeKind::Approve, WorkflowNodeState::Hold));
    }

    private function isOwnDraft(?WorkflowCaseState $state, CaseRoles $roles): bool
    {
        return $state === WorkflowCaseState::Draft && $roles->isApplicant;
    }

    private function isActiveAt(?WorkflowCaseState $state, ?WorkflowNodeKind $currentKind, ?WorkflowNodeState $currentState, WorkflowNodeKind $kind, WorkflowNodeState $nodeState): bool
    {
        return $state === WorkflowCaseState::Active && $currentKind === $kind && $currentState === $nodeState;
    }
}
