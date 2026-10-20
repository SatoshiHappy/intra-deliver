<?php

namespace Database\Seeders;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseNode;

/**
 * {@see WorkflowSeeder} の版 3(分岐 B)の案件の見本 4(docs の 設計/ワークフロー/ルートの深さ/初期データ.md 4 節の足す見本)。使う側は申請・承認・否認の業務処理と、
 * applyPlan()・contextOf()・revisionOf()・today()・userId() を持つ。
 */
trait SeedsWorkflowRouteCases
{
    /**
     * 版 3(分岐 B)の案件の見本 4(初期データ.md 4 節の足す見本。申請基準日 = 流す日)。課長は合議と責任者を指定して承認する。
     */
    private function createRouteCases(): void
    {
        $ninth = $this->applyPlan('u0001', 9, '新規顧客開拓の計画を申請します。ご確認をお願いします。', $this->today()->toDateString());
        $this->approveAs('u0002', $ninth, '計画を確認しました。合議は経理部、総務部、第二営業部の 3 人にお願いします。', ['u0009', 'u0010', 'u0016']);
        $this->approveAs('u0003', $ninth, '予算が 300 万円以上のため、部長として承認します。');
        $this->approveAs('u0009', $ninth, '総務部として問題ありません。');

        $tenth = $this->applyPlan('u0001', 10, null, $this->today()->toDateString());
        $this->approveAs('u0002', $tenth, null, ['u0009', 'u0010']);

        $eleventh = $this->applyPlan('u0001', 11, null, $this->today()->toDateString());
        $this->approveAs('u0002', $eleventh, null, ['u0009', 'u0010', 'u0016']);
        $this->approveAs('u0003', $eleventh, null);
        $this->reject->execute($this->contextOf('u0009'), $eleventh, $this->revisionOf($eleventh), $this->targetNodeIdOf($eleventh, 'u0009'), '費用対効果の根拠が不足しています。');
        $this->reject->execute($this->contextOf('u0010'), $eleventh, $this->revisionOf($eleventh), $this->targetNodeIdOf($eleventh, 'u0010'), '予算の再検討が必要です。');

        $twelfth = $this->applyPlan('u0001', 12, null, $this->today()->toDateString());
        $this->approveAs('u0002', $twelfth, null, ['u0009', 'u0010']);
        $this->approveAs('u0009', $twelfth, null);
        $this->approveAs('u0010', $twelfth, null);
        $this->approveAs('u0003', $twelfth, '責任者として承認します。');
    }

    /**
     * その人が処理対象者の現在のノードを承認する。合議の人を渡したときは、課長の承認として合議(council)と責任者(owner = u0003)を指定する。
     *
     * @param  list<string>|null  $council  合議に指定する人のコード(指定の順)
     */
    private function approveAs(string $userCode, int $caseId, ?string $comment, ?array $council = null): void
    {
        $assignments = $council === null ? [] : [
            $this->caseNodeOf($caseId, 'council')->id => array_map(fn (string $code) => $this->userId($code), $council),
            $this->caseNodeOf($caseId, 'owner')->id => [$this->userId('u0003')],
        ];
        $this->approve->execute($this->contextOf($userCode), $caseId, $this->revisionOf($caseId), $this->targetNodeIdOf($caseId, $userCode), $comment, $assignments);
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
