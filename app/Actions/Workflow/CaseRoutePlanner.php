<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowCouncilOutcome;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ノードの動きの計画の 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 9 節。どの操作も同じクラスを呼ぶ): 案件の今の行を読み、先読み(README.md 2.3)
 * → 操作するノードを処理済みにする → 進め方(2 節)を辿り、書かずに計画を作る。0 人は 422 で拒む(何も書いていない)。
 * 申請・再申請 = 申請のノードから進める。承認 = 普通の承認のノードは進める、横配置の子は次の子か親の通過、縦配置の子は合議の判定(2.5)。否認 = 縦配置の子は
 * 合議の判定、ほかは完了(否認)。承認終了・取止め = 完了。完了は片付けを含む(2.6)。承認として数える種別は {@see WorkflowAction::countsAsApproval()}。
 * 差戻し = 差戻し先と子孫を戻して差戻し先を到達させる(10.4)。引戻し = 引戻す行の node_changes を写しから戻し、操作するノードを到達させる(10.5)。
 */
class CaseRoutePlanner
{
    public function __construct(
        private CaseRouteStates $states,
        private BranchLookahead $lookahead,
        private RouteProgress $progress,
        private NodeArrival $arrival,
    ) {}

    /**
     * @throws ValidationException 先読みで真の辺の無い分岐開始・到達するノードの 0 人など(case)
     * @throws LogicException 動きの前の確かめに外れたとき
     */
    public function plan(CasePlanRequest $request): CaseRoutePlan
    {
        $state = $this->states->load($request);
        $this->lookahead->check($state);

        match ($request->action) {
            WorkflowAction::SendBack => $this->sendBack($state, $request),
            WorkflowAction::PullBack => $this->pullBack($state, $request),
            default => $this->process($state, $request),
        };

        return $state->plan($request->sendBackNodeId, $request->revertedHistory?->id);
    }

    /**
     * 操作するノードを処理済みにして進める(この操作の到達は前のノードからの到達 = 前処理者と起点の組織を書く)。
     */
    private function process(CaseRouteState $state, CasePlanRequest $request): void
    {
        $state->reaches->fromPrevious($request->previousUserId, $state->previousDepartmentId);
        $state->process($request->nodeId, $request->action);
        $this->follow($state, $request->nodeId, $request->action);
    }

    /**
     * 操作するノードを処理済みにした後の進め方。
     */
    private function follow(CaseRouteState $state, int $nodeId, WorkflowAction $action): void
    {
        $parentId = $state->node($nodeId)->parent_workflow_case_node_id;
        $parentKind = $parentId === null ? null : $state->node($parentId)->kind;
        $approves = in_array($action, WorkflowAction::countsAsApproval(), true);

        match (true) {
            in_array($action, [WorkflowAction::Apply, WorkflowAction::Reapply], true) => $this->progress->advance($state, $nodeId),
            $action === WorkflowAction::Withdraw => $state->complete(WorkflowCompletionReason::Withdrawn),
            $action === WorkflowAction::ApproveEarly => $state->complete(WorkflowCompletionReason::ApprovedEarly),
            $parentId !== null && $parentKind === WorkflowNodeKind::Parallel && ($approves || $action === WorkflowAction::Reject) => $this->decideCouncil($state, $parentId),
            $parentId !== null && $approves => $this->nextInLine($state, $parentId, (int) $state->node($nodeId)->sequence),
            $approves => $this->progress->advance($state, $nodeId),
            $action === WorkflowAction::Reject => $state->complete(WorkflowCompletionReason::Rejected),
            default => throw new LogicException("操作 {$action->value} は処理済みにして進める操作でない"),
        };
    }

    /**
     * 差戻し(操作.md 10.4 の結果): 差戻し先 M と M の子孫(差戻し元を含む。配置の展開の行も)を戻し、M を到達させる(処理対象者.md 4.5 = 差戻しの直前に M
     * (配置の親なら差戻しで到達させる子 = 縦配置は全部、横配置は子 1)が指していた履歴の行の処理者。処理済みでなかった子は今の指定の同じ番号の人。
     * {@see NodeArrival::returnTo()})。差戻し元は処理済みにしない(差戻しは履歴の行にだけ残る)。
     *
     * @throws ValidationException 戻す相手が申請基準日に有効でないとき(case)
     * @throws LogicException 差戻し先が無いとき
     */
    private function sendBack(CaseRouteState $state, CasePlanRequest $request): void
    {
        $targetId = $request->sendBackNodeId ?? throw new LogicException('差戻しの計画に差戻し先が無い');
        $state->operate($request->nodeId, WorkflowAction::SendBack);
        $this->unreachAll($state, [$targetId, ...$state->graph->reachableFrom($targetId)]);
        $this->arrival->returnTo($state, $targetId);
    }

    /**
     * 引戻し(操作.md 10.5 の結果): 引戻す行 h の node_changes の各ノードを写しから戻し、操作するノード X を戻して到達させる(処理対象者 = h の処理者 = 引戻した人)。
     *
     * @throws ValidationException 引戻した人が申請基準日に有効でない・X の申請者承認防止で申請者だけ(case)
     * @throws LogicException 引戻す行が無い・X の行でないとき
     */
    private function pullBack(CaseRouteState $state, CasePlanRequest $request): void
    {
        $reverted = $request->revertedHistory ?? throw new LogicException('引戻しの計画に引戻す行が無い');

        if ($reverted->workflow_case_node_id !== $request->nodeId) {
            throw new LogicException("引戻す行 {$reverted->id} はノード {$request->nodeId} の行でない");
        }

        $state->operate($request->nodeId, WorkflowAction::PullBack);

        foreach ($reverted->node_changes ?? [] as $change) {
            $state->restore((int) $change['node'], WorkflowNodeState::from($change['state']), $change['history'], $change['targets'], $change['taken'] ?? []);
        }

        $this->unreachAll($state, [$request->nodeId]);
        $this->arrival->reachReturned($state, $request->nodeId, [$reverted->user_id]);
    }

    /**
     * 渡したノードとその展開の行を戻す(到達していないものはそのまま)。
     *
     * @param  list<int>  $ids
     */
    private function unreachAll(CaseRouteState $state, array $ids): void
    {
        foreach ($this->progress->withChildren($state, $ids) as $id) {
            if ($state->state($id) !== WorkflowNodeState::Unreached) {
                $state->unreach($id);
            }
        }
    }

    /**
     * 横配置の子 k が承認として済んだ後: 子 k + 1 があれば到達、無ければ親を通過させて進める(2.5)。
     */
    private function nextInLine(CaseRouteState $state, int $parentId, int $sequence): void
    {
        if ($sequence < count($state->assignmentOf($parentId))) {
            $this->arrival->reachChild($state, $parentId, $sequence + 1);

            return;
        }

        $state->pass($parentId);
        $this->progress->advance($state, $parentId);
    }

    /**
     * 縦配置の合議の判定(2.5): 可決 → 残りの承認待ちの子を省き、親を通過させて進める。可決できなくなった → 完了(否認)。未決 → 待つ。
     */
    private function decideCouncil(CaseRouteState $state, int $parentId): void
    {
        $parent = $state->node($parentId);
        $assignees = count($state->assignmentOf($parentId));
        $all = $state->childrenOf($parentId);
        $children = array_filter($all, fn (int $sequence) => $sequence <= $assignees, ARRAY_FILTER_USE_KEY);
        $rule = $parent->decision_rule ?? throw new LogicException("縦配置 {$parentId} に可決の条件が無い");
        $decision = $rule->decide($parent->decision_value, $assignees, array_map(fn (int $childId) => $state->actionOf($childId), $all));

        match ($decision['outcome']) {
            WorkflowCouncilOutcome::Passed => $this->passCouncil($state, $parentId, $children),
            WorkflowCouncilOutcome::Failed => $state->complete(WorkflowCompletionReason::Rejected),
            WorkflowCouncilOutcome::Pending => null,
        };
    }

    /**
     * @param  array<int, int>  $children  番号 → 子の id
     */
    private function passCouncil(CaseRouteState $state, int $parentId, array $children): void
    {
        foreach ($children as $childId) {
            if ($state->state($childId)->isCurrent()) {
                $state->skip($childId);
            }
        }

        $state->pass($parentId);
        $this->progress->advance($state, $parentId);
    }
}
