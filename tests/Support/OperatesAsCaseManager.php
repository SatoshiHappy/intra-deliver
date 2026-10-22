<?php

namespace Tests\Support;

use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\DeleteCase;
use App\Actions\Workflow\MoveCaseNode;
use App\Actions\Workflow\ReassignCaseNode;
use App\Actions\Workflow\ReexpandCaseNode;
use App\Actions\Workflow\RetargetCaseNode;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;

/**
 * 案件操作(処理対象者の変更・再展開・ステップの移動・指定の変更・案件の削除)の業務処理を、ノードの名札({@see RunsRoutePlans::labelIds()})と人のコードで呼ぶ手助け
 * (docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15 節)。案件の管理者の既定 = 初期データのシステム管理者 u0004。成功の後に 2.7 の
 * 不変の条件と 14.1 節の 2 つの条件を確かめる({@see OperatesHolds::assertHoldInvariants()})。
 */
trait OperatesAsCaseManager
{
    use OperatesHolds;

    /** 初期データのシステム管理者(テストのフローの operate を許される) */
    protected const MANAGER = 'u0004';

    /**
     * @param  list<string>  $userCodes  変更後の処理対象者のコード
     */
    protected function retargetOn(
        WorkflowCase $case,
        string $label,
        array $userCodes,
        string $managerCode = self::MANAGER,
        ?string $comment = null,
    ): CaseOperationResult {
        $context = $this->contextOf($this->seededUser($managerCode));
        $nodeId = $this->labelIds($case)[$label];
        $result = app(RetargetCaseNode::class)
            ->execute($context, $case->id, $this->revisionOf($case), $nodeId, $this->idsOf($userCodes), $comment);
        $this->assertHoldInvariants($case);

        return $result;
    }

    protected function reexpandOn(
        WorkflowCase $case,
        string $label,
        string $managerCode = self::MANAGER,
        ?string $comment = null,
    ): CaseOperationResult {
        $context = $this->contextOf($this->seededUser($managerCode));
        $result = app(ReexpandCaseNode::class)
            ->execute($context, $case->id, $this->revisionOf($case), $this->labelIds($case)[$label], $comment);
        $this->assertHoldInvariants($case);

        return $result;
    }

    /**
     * @param  list<string>  $userCodes  指定する人のコード
     */
    protected function reassignOn(
        WorkflowCase $case,
        string $label,
        array $userCodes,
        string $managerCode = self::MANAGER,
    ): CaseOperationResult {
        $context = $this->contextOf($this->seededUser($managerCode));
        $nodeId = $this->labelIds($case)[$label];
        $result = app(ReassignCaseNode::class)
            ->execute($context, $case->id, $this->revisionOf($case), $nodeId, $this->idsOf($userCodes), null);
        $this->assertHoldInvariants($case);

        return $result;
    }

    /**
     * ステップの移動(direction = back・forward・finish。戻り先は名札)。
     */
    protected function moveOn(
        WorkflowCase $case,
        string $label,
        string $direction,
        ?string $toLabel = null,
        string $managerCode = self::MANAGER,
        ?string $comment = null,
    ): CaseOperationResult {
        $context = $this->contextOf($this->seededUser($managerCode));
        $ids = $this->labelIds($case);
        $toNodeId = $toLabel === null ? null : $ids[$toLabel];
        $result = app(MoveCaseNode::class)
            ->execute($context, $case->id, $this->revisionOf($case), $ids[$label], $direction, $toNodeId, $comment);
        $this->assertHoldInvariants($case);

        return $result;
    }

    protected function deleteCaseAs(
        WorkflowCase $case,
        string $managerCode = self::MANAGER,
        ?int $revision = null,
    ): void {
        $context = $this->contextOf($this->seededUser($managerCode));
        app(DeleteCase::class)->execute($context, $case->id, $revision ?? $this->revisionOf($case));
    }

    /**
     * ノードの到達日時(ISO 8601。無ければ null)。
     */
    protected function reachedAtOf(WorkflowCase $case, string $label): ?string
    {
        return WorkflowCaseNode::query()->findOrFail($this->labelIds($case)[$label])->reached_at?->toIso8601String();
    }

    /**
     * @param  list<string>  $userCodes
     * @return list<int>
     */
    protected function idsOf(array $userCodes): array
    {
        return array_map(fn (string $code) => $this->seededUser($code)->id, $userCodes);
    }
}
