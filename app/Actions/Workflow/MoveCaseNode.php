<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowMoveDirection;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\SendBackCandidates;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ステップの移動(案件操作の戻る・進む・終了。docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.5 節。判定の順は同じ文書の 15.1 節 =
 * {@see CaseManagerGate})。新しい進め方を作らず、差戻し・承認(申請のノードは再申請)・承認終了と同じ計画({@see CaseRoutePlanner})を呼ぶ。
 */
class MoveCaseNode
{
    public function __construct(
        private CaseManagerGate $gate,
        private CaseInputRules $inputRules,
        private SendBackCandidates $candidates,
        private CaseRoutePlanner $planner,
        private CaseWriter $writer,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(現在のノード。展開の行を含む)
     * @param  string|null  $direction  移動の種類(入力 direction = back・forward・finish)
     * @param  int|null  $sendBackNodeId  戻り先(戻るだけ。案件のノードの id)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException 案件のフローの operate を許されないとき
     * @throws ValidationException 改訂番号・ノード・表・指定するノード・計画(case)、移動の種類(direction)・戻り先(send_back_node_id)・
     *                             コメント(comment)の誤り
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        ?string $direction,
        ?int $sendBackNodeId,
        ?string $comment,
    ): CaseOperationResult {
        return DB::transaction(function () use (
            $context, $caseId, $revision, $nodeId, $direction, $sendBackNodeId, $comment,
        ) {
            $move = WorkflowMoveDirection::tryFrom((string) $direction);
            // 3 つの移動の表の行は前提が同じ(15.2 節)なので、移動の種類の誤り(判定の順の 6)より先に表を引ける
            $operation = $move?->operation() ?? WorkflowOperation::MoveFinish;
            $permit = $this->gate->lockAndPermit($operation, $context, $caseId, $revision, $nodeId);
            $move ??= throw ValidationException::withMessages(['direction' => [CaseOperationErrors::MOVE_DIRECTION]]);
            $node = $permit->node();
            $backTo = $this->checkedMove($move, $node, $sendBackNodeId);
            $comment = $this->inputRules->comment($comment);
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $case = $permit->case();
            $previousUserId = $this->previousUserOf($permit);
            $request = CasePlanRequest::byManager($case, $node->id, $move->action(), $previousUserId, $backTo);
            $plan = $this->planner->plan($request);

            $result = $this->writer->writePlan($plan, $actor, $comment);

            $this->notices->execute($result, null, $context->date);

            return $result;
        });
    }

    /**
     * 移動の種類ごとの入力の規則(戻る = 戻り先、進む = 指定するノードでない)。
     *
     * @return int|null 戻り先(戻るだけ)
     *
     * @throws ValidationException
     */
    private function checkedMove(WorkflowMoveDirection $move, WorkflowCaseNode $node, ?int $sendBackNodeId): ?int
    {
        if ($move === WorkflowMoveDirection::Forward) {
            $this->throwIfDesignator($node);
        }

        return $move === WorkflowMoveDirection::Back ? $this->requireCandidate($node->id, $sendBackNodeId) : null;
    }

    /**
     * 戻り先が祖先の候補にあるか(差戻しできる先を見ない = 15.5 節)。
     *
     * @throws ValidationException 戻り先が無い・候補に無い・候補が空(send_back_node_id)
     */
    private function requireCandidate(int $nodeId, ?int $sendBackNodeId): int
    {
        $candidates = $this->candidates->ancestorCandidatesOf($nodeId);
        $candidateIds = array_map(fn (WorkflowCaseNode $node) => $node->id, $candidates);

        if ($sendBackNodeId === null || ! in_array($sendBackNodeId, $candidateIds, true)) {
            throw ValidationException::withMessages(['send_back_node_id' => [CaseOperationErrors::MOVE_BACK_NODE]]);
        }

        return $sendBackNodeId;
    }

    /**
     * 指定するノードは進めない(指定の入力が要る = 15.5 節。一括承認と同じ規則)。
     *
     * @throws ValidationException
     */
    private function throwIfDesignator(WorkflowCaseNode $node): void
    {
        $designates = WorkflowCaseNode::query()->where('designator_workflow_case_node_id', $node->id)->exists();

        if ($designates) {
            throw CaseOperationErrors::ofCase(CaseOperationErrors::MOVE_DESIGNATOR);
        }
    }

    /**
     * 計画に渡す前処理者(設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節の 1)。処理対象者は判定に使った状況から読む
     * (判定と操作が同じ状況を見るため = {@see CaseOperationPermit})。
     *
     * @throws LogicException 操作するノードが現在のノードでないとき(判定を通った後の不変の条件の破れ)
     */
    private function previousUserOf(CaseOperationPermit $permit): int
    {
        $nodeId = $permit->node()->id;
        $current = $permit->situation->currentNode($nodeId)
            ?? throw new LogicException("移動するノード {$nodeId} が現在のノードでない");
        $targets = $current->targetUserIds;

        return count($targets) === 1 ? $targets[0] : $permit->case()->user_id;
    }
}
