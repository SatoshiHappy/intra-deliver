<?php

namespace Tests\Support;

use App\Actions\Workflow\CasePlanRequest;
use App\Actions\Workflow\CaseRoutePlan;
use App\Actions\Workflow\CaseRoutePlanner;
use App\Actions\Workflow\CaseRoutePlanWriter;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Queries\Workflow\RouteGraph;

/**
 * 計画のクラスのテストの手助け(docs の 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 14 節の「進め方」「処理対象者」): 計画を作り、操作の書く順
 * (操作.md 9 節の 2〜6)の最小の形で書く(展開の行 → 履歴の行(node_changes)→ 指定の行 → ノード → 案件の完了)。操作の業務処理に結ぶのは区切り 3-2・3-3。
 * ノードの名札 = 写したノードはコード、展開の行は「親のコード.番号」。
 */
trait RunsRoutePlans
{
    use BuildsRouteVersions;

    /** @var array<int, int> 最後の {@see self::step()} が足した展開の行(仮の id → 書いた行の id) */
    protected array $writtenChildIds = [];

    /**
     * 計画を作って書く。前処理者 = 操作する人(3a の CaseActor の処理者)。
     *
     * @param  array<string, list<string>>  $assignments  指定されたノードのコード → 人のコード(この操作の指定)
     */
    protected function step(WorkflowCase $case, string $label, WorkflowAction $action, string $actorCode, array $assignments = [], ?string $previousCode = null): CaseRoutePlan
    {
        $case = WorkflowCase::query()->findOrFail($case->id);
        $nodeId = $this->labelIds($case)[$label];
        $byNode = [];

        foreach ($assignments as $code => $userCodes) {
            $byNode[$this->labelIds($case)[$code]] = array_map(fn (string $userCode) => $this->seededUser($userCode)->id, $userCodes);
        }

        $plan = app(CaseRoutePlanner::class)->plan(new CasePlanRequest($case, $nodeId, $action, $this->seededUser($previousCode ?? $actorCode)->id, null, $byNode));
        $this->write($case, $plan, $actorCode, $byNode);

        return $plan;
    }

    /**
     * 案件のノードの今の形(名札 → 状態。現在のノードは「状態[処理対象者のコード]」)。
     *
     * @return array<string, string>
     */
    protected function shape(WorkflowCase $case): array
    {
        $codes = User::query()->pluck('code', 'id');
        $targets = WorkflowCaseNodeTarget::query()->orderBy('user_id')->get()->groupBy('workflow_case_node_id');
        $shape = [];

        foreach ($this->labelIds($case) as $label => $id) {
            $node = WorkflowCaseNode::query()->findOrFail($id);
            $users = array_map(fn (WorkflowCaseNodeTarget $target) => $codes[$target->user_id], $targets->get($id)?->all() ?? []);
            $shape[$label] = $node->state->value.($users === [] ? '' : '['.implode(',', $users).']');
        }

        return $shape;
    }

    /**
     * 名札 → ノードの id(写したノードは position の順、展開の行は親の直後に番号の順)。
     *
     * @return array<string, int>
     */
    protected function labelIds(WorkflowCase $case): array
    {
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->orderBy('position')->orderBy('sequence')->get();
        $labels = [];

        foreach ($nodes->whereNull('parent_workflow_case_node_id') as $node) {
            $labels[(string) $node->code] = $node->id;

            foreach ($nodes->where('parent_workflow_case_node_id', $node->id) as $child) {
                $labels["{$node->code}.{$child->sequence}"] = $child->id;
            }
        }

        return $labels;
    }

    /**
     * 不変の条件の 5 つ(README.md 2.7)と、指定の行の履歴が「指定されたノード Y の指定するノード X の承認の行」であること(データモデル/案件の表.md 3.5)。
     */
    protected function assertRouteInvariants(WorkflowCase $case): void
    {
        $case = WorkflowCase::query()->findOrFail($case->id);
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->get()->keyBy('id');
        $byId = $nodes->all();
        $edges = WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->orderBy('sort_key')->get();
        $graph = RouteGraph::of(array_values($nodes->whereNull('parent_workflow_case_node_id')->modelKeys()), array_values($edges->map(fn (WorkflowCaseEdge $edge) => [$edge->from_workflow_case_node_id, $edge->to_workflow_case_node_id])->all()));
        $current = $nodes->filter(fn (WorkflowCaseNode $node) => $node->state->isCurrent());

        $this->assertSame($case->state === WorkflowCaseState::Active, $current->isNotEmpty(), '1: 未完了 ⇔ 現在のノードがある');

        foreach ($current as $node) {
            foreach ($graph->ancestorsOf($node->parent_workflow_case_node_id ?? $node->id) as $ancestorId) {
                $this->assertTrue($byId[$ancestorId]->state->isSettled(), "2: 現在のノード {$node->id} の祖先 {$ancestorId} が済んでいない");
            }
        }

        foreach ($nodes->whereNotNull('parent_workflow_case_node_id')->groupBy('parent_workflow_case_node_id') as $parentId => $children) {
            $this->assertChildren($byId[$parentId], array_values($children->sortBy('sequence')->all()));
        }

        foreach ($nodes->where('kind', WorkflowNodeKind::BranchStart) as $branch) {
            $this->assertBranch($graph, $byId, $branch, array_values($edges->where('from_workflow_case_node_id', $branch->id)->all()));
        }

        foreach (WorkflowCaseAssignment::query()->where('workflow_case_id', $case->id)->get() as $row) {
            $history = WorkflowCaseHistory::query()->findOrFail($row->workflow_case_history_id);
            $this->assertSame([WorkflowAction::Approve, $byId[$row->workflow_case_node_id]->designator_workflow_case_node_id], [$history->action, $history->workflow_case_node_id], "指定の行 {$row->id} の履歴が指定するノードの承認の行でない");
        }
    }

    /**
     * @param  list<WorkflowCaseNode>  $children
     */
    private function assertChildren(WorkflowCaseNode $parent, array $children): void
    {
        $currentSequences = array_values(array_map(fn (WorkflowCaseNode $child) => $child->sequence, array_filter($children, fn (WorkflowCaseNode $child) => $child->state->isCurrent())));

        if ($parent->state !== WorkflowNodeState::Running) {
            $this->assertSame([], $currentSequences, "3: 展開中でない親 {$parent->code} の子が現在のノード");

            return;
        }

        if ($parent->kind === WorkflowNodeKind::Serial) {
            $this->assertCount(1, $currentSequences, "3: 横配置 {$parent->code} の現在の子がちょうど 1 つでない");
            $earlier = array_filter($children, fn (WorkflowCaseNode $child) => $child->sequence < $currentSequences[0]);
            $this->assertSame(array_map(fn () => WorkflowNodeState::Done, $earlier), array_map(fn (WorkflowCaseNode $child) => $child->state, $earlier), '3: 前の番号の子が処理済みでない');
        } else {
            $this->assertNotSame([], $currentSequences, "3: 縦配置 {$parent->code} に現在の子が無い");
        }
    }

    /**
     * @param  array<int, WorkflowCaseNode>  $nodes
     * @param  list<WorkflowCaseEdge>  $edges
     */
    private function assertBranch(RouteGraph $graph, array $nodes, WorkflowCaseNode $branch, array $edges): void
    {
        $closingId = (int) $branch->closing_workflow_case_node_id;
        $section = $graph->section($branch->id, $closingId);
        $taken = array_map(fn (WorkflowCaseEdge $edge) => $edge->taken, $edges);

        if ($branch->state === WorkflowNodeState::Unreached) {
            $this->assertSame([], array_filter($section, fn (int $id) => $nodes[$id]->state !== WorkflowNodeState::Unreached), '4: 到達していない分岐の区間に動いたノード');
            $this->assertSame([], array_filter($taken, fn (?bool $value) => $value !== null), '5: 到達していない分岐の taken');

            return;
        }

        if ($branch->state !== WorkflowNodeState::Passed) {
            return;
        }

        $this->assertSame([], array_filter($taken, fn (?bool $value) => $value === null), '5: 通過した分岐の taken に NULL');
        $kept = [];

        foreach ($edges as $edge) {
            $start = $edge->to_workflow_case_node_id;
            $kept = $edge->taken && $start !== $closingId ? [...$kept, $start, ...$graph->reachableFrom($start, [$closingId])] : $kept;
        }

        foreach (array_diff($section, $kept) as $id) {
            $this->assertSame(WorkflowNodeState::Skipped, $nodes[$id]->state, "4: 偽の区間のノード {$nodes[$id]->code} が省略でない");
        }
    }

    /**
     * 操作の書く順の最小の形(展開の行 → 履歴の行 → 指定の行 → ノード → 案件)。
     *
     * @param  array<int, list<int>>  $assignments
     */
    private function write(WorkflowCase $case, CaseRoutePlan $plan, string $actorCode, array $assignments): void
    {
        $writer = app(CaseRoutePlanWriter::class);
        $ids = $writer->addChildren($plan);
        $this->writtenChildIds = $ids;
        // 申請・再申請の行が指す版はファクトリが作る
        $history = WorkflowCaseHistory::factory()->createOne([
            'workflow_case_id' => $case->id, 'workflow_case_node_id' => $plan->processedNodeId, 'action' => $plan->action, 'user_id' => $this->seededUser($actorCode)->id,
            'send_back_workflow_case_node_id' => null, 'reverted_workflow_case_history_id' => null,
            'node_changes' => $plan->nodeChanges($ids),
        ]);

        foreach ($assignments as $nodeId => $userIds) {
            foreach ($userIds as $index => $userId) {
                WorkflowCaseAssignment::query()->create(['workflow_case_id' => $case->id, 'workflow_case_history_id' => $history->id, 'workflow_case_node_id' => $nodeId, 'sequence' => $index + 1, 'user_id' => $userId]);
            }
        }

        $writer->apply($plan, $ids, $history->id);
        $case->update(['revision' => $case->revision + 1, ...($plan->completion === null ? [] : ['state' => WorkflowCaseState::Completed, 'completion_reason' => $plan->completion, 'completed_at' => now()])]);
    }
}
