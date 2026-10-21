<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeState;

/**
 * 処理者 P としての役割の判定(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 3.1 節・4.3 節)。P = 状況の処理者
 * ({@see CaseSituation::$processorUserId})、自分 = 判定を求める者。業務処理の判定と画面の can が食い違わないよう、
 * どちらも {@see CaseOperationDecider} からここを通る。
 */
class ProcessorRoles
{
    /**
     * @param  int|null  $userId  判定を求める者の d に有効なユーザー(無ければ null)
     * @param  bool  $isPullBackActor  引戻しの 1 規則に合うか(呼び手が、問うノードか、どれかのノードで決める)
     * @param  bool  $withoutOwnRow  ノードの処理対象者で P の本人の行を問わない(代理元を選んだ誤りは選んだ処理担当者の誤りとして
     *                               出すため = 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.2 節の「処理者の決め方の境界」)
     */
    public function of(
        ?int $userId,
        CaseSituation $situation,
        ?CurrentNode $node,
        bool $isPullBackActor,
        bool $canApply,
        bool $canUpdateRecord,
        bool $withoutOwnRow = false,
    ): CaseRoles {
        $processor = $situation->processorUserId;

        if ($userId === null || $processor === null) {
            return new CaseRoles(false, false, false, false, false);
        }

        return new CaseRoles(
            isApplicant: $situation->case->user_id === $processor,
            isCurrentTarget: $node !== null && self::isTarget($userId, $processor, $situation, $node, $withoutOwnRow),
            isPullBackActor: $isPullBackActor,
            canApply: $canApply,
            canUpdateRecord: $canUpdateRecord,
            isHoldSide: $node !== null && self::isHolder($processor, $situation, $node),
            isConfirmTarget: $situation->confirmNodeIds !== [],
        );
    }

    /**
     * 状況の処理者 P が、その現在のノードの保留者側か(表の保留解除の「誰が」と同じ規則。添付の可否の保留解除が読む)。
     */
    public static function isHoldSideAt(CaseSituation $situation, int $nodeId): bool
    {
        $node = $situation->currentNode($nodeId);
        $processor = $situation->processorUserId;

        return $node !== null && $processor !== null && self::isHolder($processor, $situation, $node);
    }

    private static function isHolder(int $processor, CaseSituation $situation, CurrentNode $node): bool
    {
        return $node->state === WorkflowNodeState::Hold && $situation->holderOf($node->id) === $processor
            && in_array($processor, $node->targetUserIds, true);
    }

    private static function isTarget(
        int $userId,
        int $processor,
        CaseSituation $situation,
        CurrentNode $node,
        bool $withoutOwnRow,
    ): bool {
        if ($withoutOwnRow) {
            return $processor !== $userId || $situation->isTargetOf($node);
        }

        return $situation->isTargetOf($node) && in_array($processor, $node->targetUserIds, true);
    }
}
