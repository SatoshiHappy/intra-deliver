<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 初期データの版 3(分岐 B = docs の 設計/ワークフロー/ルートの深さ/初期データ.md 3.2)の案件を業務処理で作る HTTP のテストの手助け: 申請(申請基準日 = 今日)と、
 * その人が処理対象者の現在のノードの承認(課長は合議・責任者の指定つき)。判定の入力は今日・接続元なし・選んだ組織なし。
 */
trait AppliesOnTheBranchRoute
{
    /**
     * 計画のフローに申請する(予算で分岐する。担当部署 = 東京営業課、開始日あり)。
     */
    private function applyOnBranch(string $applicantCode, int $budget): int
    {
        $input = new ApplicationInput(null, null, new RecordInput([
            'name' => "予算 {$budget} の計画", 'department' => Department::query()->where('code', 'tokyo-sales')->sole()->id, 'manager' => $this->branchUserId($applicantCode),
            'status' => 'planning', 'start_date' => '2026-11-01', 'budget' => $budget,
        ]));

        return app(ApplyCase::class)->execute($this->branchContext($applicantCode), 'project_plan', $input, $this->branchToday()->toDateString())->caseId;
    }

    /**
     * その人が処理対象者の現在のノード(ちょうど 1 つ)を承認する。
     *
     * @param  array<string, list<string>>  $assignments  指定されるノードのコード → 人のコード
     */
    private function approveOnBranch(int $caseId, string $userCode, array $assignments = []): void
    {
        $node = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->whereIn('state', WorkflowNodeState::current())
            ->whereHas('workflowCaseNodeTargets', fn ($targets) => $targets->where('user_id', $this->branchUserId($userCode)))->sole();
        $ids = [];

        foreach ($assignments as $code => $people) {
            $ids[WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('code', $code)->sole()->id] = array_map(fn (string $person) => $this->branchUserId($person), $people);
        }

        app(ApproveCase::class)->execute($this->branchContext($userCode), $caseId, WorkflowCase::query()->findOrFail($caseId)->revision, $node->id, null, $ids);
    }

    private function branchContext(string $userCode): AccessContext
    {
        return new AccessContext($this->branchUserId($userCode), null, null, $this->branchToday(), AccessContext::LOCALE_CODE);
    }

    private function branchToday(): CarbonImmutable
    {
        return CarbonImmutable::today(AccessContext::TIMEZONE);
    }

    private function branchUserId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }
}
