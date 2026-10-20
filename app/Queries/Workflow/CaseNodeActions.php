<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use DateTimeInterface;
use LogicException;

/**
 * 案件の詳細のノードごとの操作の可否(docs の 設計/ワークフロー/ルートの深さ/画面.md 6・7 節の nodeActions): 判定を求める者が
 * 処理対象者の現在のノードごとに、承認・承認終了・否認・差戻しを状態 × 役割の表 {@see CaseOperationDecider::permitsIn()}(業務処理と同じ口)で引き、差戻しは候補
 * {@see SendBackCandidates}(業務処理と同じ規則)が空なら偽にする。候補と可否はここでだけ作り、呼び手は上書きしない。blockedReason = 申請者承認防止に当たれば
 * 業務処理と同じ文言({@see ApplicantApprovalGuard}。業務処理が正本で、画面は「決定」を押せなくするだけ = 処理対象者.md 6.1)。指定の欄は {@see CaseDesignations}。
 * sendBackRestricted = 差戻しの制限の 1 行を出すか、isCouncilVote = 縦配置の子(否認のダイアログの文を変える = 画面.md 6 節)。
 * 鍵を取らない。
 *
 * @phpstan-type NodeAction array{nodeId: int, name: string, approve: bool, approveEarly: bool, reject: bool, sendBack: bool, blockedReason: string|null, sendBackTargets: list<array{id: int, name: string}>, sendBackRestricted: bool, isCouncilVote: bool}
 */
class CaseNodeActions
{
    /** 申請者承認防止で拒む操作(処理対象者.md 6.1) */
    private const GUARDED = ['approve', 'approveEarly', 'reject'];

    public function __construct(
        private CaseOperationDecider $decider,
        private SendBackCandidates $sendBackCandidates,
        private ApplicantApprovalGuard $guard,
        private SubjectGroupMembership $membership,
    ) {}

    /**
     * 処理対象者の現在のノード(写した並び)のうち、どれかの操作が許されるもの。
     *
     * @return list<NodeAction>
     */
    public function of(Requester $requester, DateTimeInterface $date, CaseSituation $situation): array
    {
        $userId = $this->membership->effectiveUserId($requester, $date);
        $targets = array_values(array_filter($situation->currentNodes, fn (CurrentNode $node) => $situation->isTargetOf($node)));

        if ($userId === null || $targets === []) {
            return [];
        }

        $rows = WorkflowCaseNode::query()->with('parent')->whereIn('id', array_map(fn (CurrentNode $node) => $node->id, $targets))->get()->keyBy('id');
        $actions = [];

        foreach ($targets as $node) {
            $permits = fn (WorkflowOperation $operation) => $this->decider->permitsIn($operation, $requester, $date, $situation, $node->id);
            $sendBack = $permits(WorkflowOperation::SendBack) ? $this->sendBackCandidates->withRestriction($node->id) : ['candidates' => [], 'isRestricted' => false];
            $sendBackTargets = array_map(fn (WorkflowCaseNode $candidate) => ['id' => $candidate->id, 'name' => $candidate->name], $sendBack['candidates']);
            $row = $rows->get($node->id) ?? throw new LogicException("現在のノード {$node->id} の行が無い");
            $action = [
                'nodeId' => $node->id,
                'name' => $node->name,
                'approve' => $permits(WorkflowOperation::Approve),
                'approveEarly' => $permits(WorkflowOperation::ApproveEarly),
                'reject' => $permits(WorkflowOperation::Reject),
                'sendBack' => $sendBackTargets !== [],
                'blockedReason' => null,
                'sendBackTargets' => $sendBackTargets,
                'sendBackRestricted' => $sendBack['isRestricted'],
                'isCouncilVote' => $row->parent?->kind === WorkflowNodeKind::Parallel,
            ];
            $action['blockedReason'] = $this->blockedReason($row, $situation, $userId, $action);

            if ($action['approve'] || $action['approveEarly'] || $action['reject'] || $action['sendBack']) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * 申請者承認防止の文言(防止で拒む操作のどれかが表で許され、ノードの旗が立ち、操作する人が拒まれる人のとき。ほかは null)。
     *
     * @param  array{approve: bool, approveEarly: bool, reject: bool}  $action
     */
    private function blockedReason(WorkflowCaseNode $node, CaseSituation $situation, int $userId, array $action): ?string
    {
        $isGuarded = array_filter(array_intersect_key($action, array_flip(self::GUARDED))) !== [];

        if (! $isGuarded || ! $node->prevents_applicant_approval) {
            return null;
        }

        // 札の操作する人 = 処理者 P = 自分
        $term = $this->guard->rejectingTerm(CaseActor::of($userId), $situation->case);

        return $term === null ? null : ApplicantApprovalGuard::approvalMessage($term);
    }
}
