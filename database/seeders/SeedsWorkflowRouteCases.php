<?php

namespace Database\Seeders;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseNode;

/**
 * {@see WorkflowSeeder} の版 3(分岐 B)の案件の見本 4(docs の 設計/ワークフロー/ルートの深さ/初期データ.md 4 節の足す見本)。使う側は申請・承認・否認の業務処理と、
 * applyPlan()・contextAt()・revisionOf()・seedingDay()・userId() を持つ。
 */
trait SeedsWorkflowRouteCases
{
    /**
     * 版 3(分岐 B)の案件の見本 4(初期データ.md 4 節の足す見本。申請基準日 = 流す日)。課長は合議と責任者を指定して承認する。
     * 操作の時刻 = 設計/ワークフロー/案件の運用/初期データ.md 4.2(基準の日に書いた値)。
     */
    private function createRouteCases(): void
    {
        $base = $this->seedingDay()->toDateString();
        ['caseId' => $ninth] = $this->applyPlan('2026-10-07 09:00', 'u0001', 9, '新規顧客開拓の計画を申請します。ご確認をお願いします。', $base);
        $comment = '計画を確認しました。合議は経理部、総務部、第二営業部の 3 人にお願いします。';
        $this->approveAs('2026-10-08 10:00', 'u0002', $ninth, $comment, ['u0009', 'u0010', 'u0016']);
        $this->approveAs('2026-10-09 11:00', 'u0003', $ninth, '予算が 300 万円以上のため、部長として承認します。');
        $this->approveAs('2026-10-10 13:00', 'u0009', $ninth, '総務部として問題ありません。');

        ['caseId' => $tenth] = $this->applyPlan('2026-10-11 09:00', 'u0001', 10, null, $base);
        $this->approveAs('2026-10-12 10:00', 'u0002', $tenth, null, ['u0009', 'u0010']);

        ['caseId' => $eleventh] = $this->applyPlan('2026-09-30 09:00', 'u0001', 11, null, $base);
        $this->approveAs('2026-10-01 10:00', 'u0002', $eleventh, null, ['u0009', 'u0010', 'u0016']);
        $this->approveAs('2026-10-02 11:00', 'u0003', $eleventh, null);
        $this->rejectAs('2026-10-03 13:00', 'u0009', $eleventh, '費用対効果の根拠が不足しています。');
        $this->rejectAs('2026-10-03 15:00', 'u0010', $eleventh, '予算の再検討が必要です。');

        ['caseId' => $twelfth] = $this->applyPlan('2026-09-10 09:00', 'u0001', 12, null, $base);
        $this->approveAs('2026-09-11 10:00', 'u0002', $twelfth, null, ['u0009', 'u0010']);
        $this->approveAs('2026-09-12 11:00', 'u0009', $twelfth, null);
        $this->approveAs('2026-09-12 14:00', 'u0010', $twelfth, null);
        $this->approveAs('2026-09-13 10:00', 'u0003', $twelfth, '責任者として承認します。');
    }

    /**
     * その人が処理対象者の現在のノードを承認する。合議の人を渡したときは、課長の承認として合議(council)と責任者(owner = u0003)を指定する。
     *
     * @param  list<string>|null  $council  合議に指定する人のコード(指定の順)
     */
    private function approveAs(
        string $at,
        string $userCode,
        int $caseId,
        ?string $comment,
        ?array $council = null,
    ): void {
        $assignments = $council === null ? [] : [
            $this->caseNodeOf($caseId, 'council')->id => array_map(fn (string $code) => $this->userId($code), $council),
            $this->caseNodeOf($caseId, 'owner')->id => [$this->userId('u0003')],
        ];
        $nodeId = $this->targetNodeIdOf($caseId, $userCode);
        $context = $this->contextAt($at, $userCode);
        $this->approve->execute($context, $caseId, $this->revisionOf($caseId), $nodeId, $comment, $assignments);
    }

    /**
     * その人が処理対象者の現在のノードを否認する。
     */
    private function rejectAs(string $at, string $userCode, int $caseId, string $comment): void
    {
        $nodeId = $this->targetNodeIdOf($caseId, $userCode);
        $context = $this->contextAt($at, $userCode);
        $this->reject->execute($context, $caseId, $this->revisionOf($caseId), $nodeId, $comment);
    }

    /**
     * その人が処理対象者の現在のノード(合議の子を含む。見本ではちょうど 1 つ)。
     */
    private function targetNodeIdOf(int $caseId, string $userCode): int
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->whereIn('state', WorkflowNodeState::current())
            ->whereHas('workflowCaseNodeTargets', fn ($targets) => $targets->where('user_id', $this->userId($userCode)))->sole()->id;
    }

    private function caseNodeOf(int $caseId, string $code): WorkflowCaseNode
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('code', $code)->sole();
    }
}
