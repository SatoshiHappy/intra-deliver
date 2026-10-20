<?php

namespace Tests\Support;

use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\HoldCase;
use App\Actions\Workflow\ReleaseCase;
use App\Actions\Workflow\TransferCase;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 保留・保留解除・振替の業務処理を、ノードの名札({@see RunsRoutePlans::labelIds()})と人のコードで呼ぶ手助け(docs の
 * 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.1 節)。成功の後に 2.7 の不変の条件と、現在のノードは到達日時を持ち、
 * 保留中のノードは処理対象者を 1 人以上持つことを確かめる。代理元は $principalCode(null = 自分として)。
 */
trait OperatesHolds
{
    use OperatesOnRoutes;

    protected function holdOn(
        WorkflowCase $case,
        string $label,
        string $userCode,
        ?string $principalCode = null,
    ): CaseOperationResult {
        return $this->onNode(HoldCase::class, $case, $label, $userCode, $principalCode);
    }

    protected function releaseOn(
        WorkflowCase $case,
        string $label,
        string $userCode,
        ?string $principalCode = null,
    ): CaseOperationResult {
        return $this->onNode(ReleaseCase::class, $case, $label, $userCode, $principalCode);
    }

    protected function transferOn(
        WorkflowCase $case,
        string $label,
        string $userCode,
        ?int $toUserId,
        ?string $principalCode = null,
    ): CaseOperationResult {
        $context = $this->contextOf($this->seededUser($userCode));
        $principalId = $principalCode === null ? null : $this->seededUser($principalCode)->id;
        $nodeId = $this->labelIds($case)[$label];
        $revision = $this->revisionOf($case);
        $result = app(TransferCase::class)
            ->execute($context, $case->id, $revision, $nodeId, $toUserId, null, $principalId);
        $this->assertHoldInvariants($case);

        return $result;
    }

    /**
     * 2.7 の 5 つと、現在のノードは到達日時を持つ・保留中のノードは処理対象者を 1 人以上持つ(14.1 節)。
     */
    protected function assertHoldInvariants(WorkflowCase $case): void
    {
        $this->assertRouteInvariants($case);
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->get();

        foreach ($nodes->filter(fn (WorkflowCaseNode $node) => $node->state->isCurrent()) as $node) {
            $this->assertNotNull($node->reached_at, "現在のノード {$node->id} に到達日時が無い");
        }

        foreach ($nodes->where('state', WorkflowNodeState::Hold) as $node) {
            $count = WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $node->id)->count();
            $this->assertGreaterThan(0, $count, "保留中のノード {$node->id} に処理対象者が無い");
        }
    }

    /**
     * 最新の履歴の行の [種別, 処理者のコード, 実行者のコード, node_changes, 履歴の人のコード]。
     *
     * @return array{string, string, string|null, mixed, list<string>}
     */
    protected function latestRow(WorkflowCase $case): array
    {
        $row = WorkflowCaseHistory::query()->with(['actor', 'executor'])->where('workflow_case_id', $case->id)
            ->latest('id')->firstOrFail();
        $people = WorkflowCaseHistoryUser::query()->with('user')->where('workflow_case_history_id', $row->id)
            ->orderBy('sequence')->get();

        return [
            $row->action->value,
            (string) $row->actor?->code,
            $row->executor?->code,
            $row->node_changes,
            array_values($people->map(fn (WorkflowCaseHistoryUser $person) => (string) $person->user?->code)->all()),
        ];
    }

    /**
     * 操作を savepoint の中で行い、'success' か 422 のキー → 文言を返して巻き戻す。
     *
     * @param  Closure(): mixed  $operate
     * @return string|array<string, string>
     */
    protected function attemptOf(Closure $operate): string|array
    {
        DB::beginTransaction();

        try {
            $operate();

            return 'success';
        } catch (ValidationException $exception) {
            return array_map(fn (array $messages) => (string) $messages[0], $exception->errors());
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  class-string<HoldCase|ReleaseCase>  $operation
     */
    private function onNode(
        string $operation,
        WorkflowCase $case,
        string $label,
        string $userCode,
        ?string $principalCode,
    ): CaseOperationResult {
        $context = $this->contextOf($this->seededUser($userCode));
        $principalId = $principalCode === null ? null : $this->seededUser($principalCode)->id;
        $nodeId = $this->labelIds($case)[$label];
        $result = app($operation)->execute($context, $case->id, $this->revisionOf($case), $nodeId, null, $principalId);
        $this->assertHoldInvariants($case);

        return $result;
    }
}
