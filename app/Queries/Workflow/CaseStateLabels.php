<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowNodeState;
use LogicException;

/**
 * 案件の状態の札の語と色をサーバーで作る 1 か所(規則 = docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節、語と色 =
 * 設計/ワークフロー/画面/ホームと意匠とE2E.md の 8 節)。業務レコードの申請の節と持ち主の 1 行の札が食い違わないよう、両方がここを通る
 * (設計/ワークフロー/業務データとの接続/申請と書き込み.md の 4 節)。
 */
final class CaseStateLabels
{
    /** 現在のノードが無い未完了の札(画面の札と同じ) */
    private const ACTIVE_FALLBACK = ['承認待ち', 'pending'];

    /**
     * 札の語と色。完了で完了の理由が無いのはデータの不整合なので握らない。
     *
     * @return array{string, 'pending'|'returned'|'settled'|'rejected'}
     *
     * @throws LogicException 完了の案件が完了の理由を持たないとき
     */
    public static function of(
        int $caseId,
        WorkflowCaseState $state,
        ?WorkflowCompletionReason $reason,
        ?WorkflowNodeState $labelState,
    ): array {
        return match ($state) {
            WorkflowCaseState::Draft => ['一時保存', 'settled'],
            WorkflowCaseState::Completed => self::completedOf(
                $reason ?? throw new LogicException("完了した案件 {$caseId} が完了の理由を持たない"),
            ),
            WorkflowCaseState::Active => $labelState === null
                ? self::ACTIVE_FALLBACK
                : WorkflowHomeLabels::CURRENT_NODE_STATES[$labelState->value]
                    ?? throw new LogicException("案件 {$caseId} の札の状態 {$labelState->value} に札の語が無い"),
        };
    }

    /**
     * 完了の札(理由を足したら match が漏れを止める)。
     *
     * @return array{string, 'settled'|'rejected'}
     */
    private static function completedOf(WorkflowCompletionReason $reason): array
    {
        return match ($reason) {
            WorkflowCompletionReason::Approved => ['完了(承認)', 'settled'],
            WorkflowCompletionReason::ApprovedEarly => ['完了(承認終了)', 'settled'],
            WorkflowCompletionReason::Rejected => ['完了(否認)', 'rejected'],
            WorkflowCompletionReason::Withdrawn => ['完了(取止め)', 'settled'],
        };
    }
}
