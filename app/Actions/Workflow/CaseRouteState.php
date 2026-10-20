<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\RouteGraph;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * 計画の作業の状態(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 9 節の 1。書かない): 読んだ案件のノード・辺・処理対象者・今の指定の上で、
 * ノードの動き(README.md 2.1 = 到達させる・展開する・処理済みにする・通過させる・省く)を写しの値に積み、{@see self::plan()} で前後の差を計画にする。
 * 動きの前の確かめ(到達させる・展開するノードは到達していない、省く前は省ける状態)に外れたら LogicException。新しい展開の行は書いていない
 * モデル(仮の負の id)で持つ。作るのは {@see CaseRouteStates::load()}。
 */
final class CaseRouteState
{
    /** @var array<int, WorkflowNodeState> */
    private array $states = [];

    /** @var array<int, int|null> */
    private array $histories = [];

    /** @var array<int, WorkflowCaseNode> 仮の id → 書いていない展開の行 */
    private array $newChildren = [];

    /** @var array<int, list<int>> 読んだ時の処理対象者 */
    private readonly array $originalTargets;

    private ?int $processedNodeId = null;

    private ?WorkflowAction $action = null;

    private ?WorkflowCompletionReason $completion = null;

    /** 到達日時と前処理者を書くノードの記録 */
    public readonly CaseReaches $reaches;

    /**
     * @param  array<int, WorkflowCaseNode>  $nodes  id → 案件のノード(展開の行を含む。親を読み込み済み)
     * @param  CaseEdgeState  $edges  案件の辺(出る辺・taken・条件の評価)
     * @param  array<int, list<int>>  $targets  ノードの id → 処理対象者(昇順)
     * @param  array<int, WorkflowAction>  $historyActions  履歴の行の id → 種別(処理済みのノードが指す行)
     * @param  array<int, list<int>>  $assignments  指定されたノードの id → 今の指定(この操作の指定で上書き済み)
     * @param  int|null  $previousDepartmentId  前処理者の起点の組織
     * @param  CarbonImmutable  $baseDate  案件の申請基準日(人の有効性を見る日)
     */
    public function __construct(
        public readonly WorkflowCase $case,
        private array $nodes,
        public readonly CaseEdgeState $edges,
        public readonly RouteGraph $graph,
        private array $targets,
        private readonly array $historyActions,
        private readonly array $assignments,
        public readonly ?int $previousDepartmentId,
        public readonly CarbonImmutable $baseDate,
    ) {
        foreach ($nodes as $id => $node) {
            $this->states[$id] = $node->state;
            $this->histories[$id] = $node->workflow_case_history_id;
            $this->targets[$id] ??= [];
        }

        $this->originalTargets = $this->targets;
        $this->reaches = new CaseReaches;
    }

    public function node(int $id): WorkflowCaseNode
    {
        return $this->nodes[$id] ?? throw new LogicException("案件 {$this->case->id} にノード {$id} が無い");
    }

    public function state(int $id): WorkflowNodeState
    {
        return $this->states[$id];
    }

    /**
     * 展開の行(仮のものを含む)。
     *
     * @return array<int, int> 番号 → ノードの id(番号の順)
     */
    public function childrenOf(int $parentId): array
    {
        $children = [];

        foreach ($this->nodes as $id => $node) {
            if ($node->parent_workflow_case_node_id === $parentId) {
                $children[(int) $node->sequence] = $id;
            }
        }

        ksort($children);

        return $children;
    }

    /**
     * 指定されたノードの今の指定。
     *
     * @return list<int>
     *
     * @throws LogicException 指定するノードの承認が無いとき(不変の条件の破れ)
     */
    public function assignmentOf(int $id): array
    {
        return $this->assignments[$id] ?? throw new LogicException("ノード {$id} の今の指定が無い");
    }

    /**
     * 処理済みのノードを処理済みにした種別(この操作で処理したノードはこの操作の種別。処理済みでなければ null)。
     */
    public function actionOf(int $id): ?WorkflowAction
    {
        if ($this->states[$id] !== WorkflowNodeState::Done) {
            return null;
        }

        return $id === $this->processedNodeId ? $this->action : $this->historyActions[(int) $this->histories[$id]] ?? null;
    }

    public function isCompleted(): bool
    {
        return $this->completion !== null;
    }

    /**
     * 処理済みにする(操作するノード。この操作の履歴の行を指す)。前の状態 = 現在のノード(申請は申請の書き込みの途中の到達していないも)。
     */
    public function process(int $id, WorkflowAction $action): void
    {
        $from = $this->states[$id] ?? throw new LogicException("案件 {$this->case->id} にノード {$id} が無い");
        $allowed = $from->isCurrent() || ($from === WorkflowNodeState::Unreached && $this->node($id)->kind === WorkflowNodeKind::Apply);
        $this->move($id, $allowed, WorkflowNodeState::Done);
        $this->processedNodeId = $id;
        $this->action = $action;
    }

    /**
     * 到達させる(承認待ち・再申請待ち。処理対象者を入れ直し、到達の記録に足す)。
     *
     * @param  non-empty-list<int>  $userIds
     */
    public function reach(int $id, array $userIds): void
    {
        $this->move($id, $this->states[$id] === WorkflowNodeState::Unreached, $this->node($id)->kind === WorkflowNodeKind::Apply ? WorkflowNodeState::Reapply : WorkflowNodeState::Waiting);
        $sorted = array_values(array_unique($userIds));
        sort($sorted);
        $this->targets[$id] = $sorted;
        $this->reaches->add($id);
    }

    /**
     * 展開する(横配置・縦配置 = 展開中)。
     */
    public function expand(int $id): void
    {
        $this->move($id, $this->states[$id] === WorkflowNodeState::Unreached, WorkflowNodeState::Running);
    }

    /**
     * 通過させる(分岐・同期は到達していないから、横配置・縦配置は展開中から)。
     */
    public function pass(int $id): void
    {
        $this->move($id, in_array($this->states[$id], WorkflowNodeState::passable(), true), WorkflowNodeState::Passed);
    }

    /**
     * 省く(省く前は到達していない・展開中・現在のノードの状態の一覧だけ)。
     */
    public function skip(int $id): void
    {
        $this->move($id, in_array($this->states[$id], WorkflowNodeState::skippable(), true) && $this->node($id)->kind !== WorkflowNodeKind::Apply, WorkflowNodeState::Skipped);
    }

    /**
     * 戻す(到達していない。処理対象者と履歴の参照を外す。分岐開始なら出る辺の taken を NULL にする = README.md 2.1)。どの状態からでも戻せる。
     */
    public function unreach(int $id): void
    {
        $this->move($id, true, WorkflowNodeState::Unreached);

        if ($this->node($id)->kind === WorkflowNodeKind::BranchStart) {
            foreach ($this->edges->from($id) as $edge) {
                $this->edges->setTaken($edge, null);
            }
        }
    }

    /**
     * 写しから戻す(引戻し = 操作.md 10.5: node_changes の状態・履歴の参照・処理対象者と、分岐開始なら出る辺の taken)。どの状態からでも戻せる。
     *
     * @param  list<int>  $targets
     * @param  array<int, bool|null>  $taken  出る辺の id → taken(分岐開始だけ)
     */
    public function restore(int $id, WorkflowNodeState $state, ?int $history, array $targets, array $taken): void
    {
        $this->node($id);
        [$this->states[$id], $this->histories[$id], $this->targets[$id]] = [$state, $history, $targets];
        $this->edges->restoreTaken($taken);
    }

    /**
     * 操作するノードを記録する(処理済みにしない操作 = 差戻し(差戻し元は子孫として戻す = 10.4)・引戻し(到達させ直す = 10.5))。
     */
    public function operate(int $id, WorkflowAction $action): void
    {
        $this->node($id);
        $this->processedNodeId = $id;
        $this->action = $action;
    }

    /**
     * 展開の行を足す(書いていない行。名前 = 「承認 <番号>」)。親の値を写す列(申請者承認防止・期限の日数)は
     * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節。
     *
     * @return int 仮の id(負)
     */
    public function addChild(int $parentId, int $sequence): int
    {
        $parent = $this->node($parentId);
        $id = -(count($this->newChildren) + 1);
        $child = new WorkflowCaseNode([
            'workflow_case_id' => $this->case->id, 'name' => "承認 {$sequence}", 'kind' => WorkflowNodeKind::Approve, 'parent_workflow_case_node_id' => $parentId,
            'sequence' => $sequence, 'prevents_applicant_approval' => $parent->prevents_applicant_approval, 'state' => WorkflowNodeState::Unreached,
            'deadline_days' => $parent->deadline_days,
        ]);
        $child->setRelation('parent', $parent);
        $this->newChildren[$id] = $child;
        $this->nodes[$id] = $child;
        $this->states[$id] = WorkflowNodeState::Unreached;
        $this->histories[$id] = null;
        $this->targets[$id] = [];

        return $id;
    }

    /**
     * 案件を完了にする(設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.6 の片付け: 残っている現在のノードと
     * 展開中の親を省く。到達していないノードはそのまま)。
     */
    public function complete(WorkflowCompletionReason $reason): void
    {
        foreach ($this->states as $id => $state) {
            if ($state->isCurrent() || $state === WorkflowNodeState::Running) {
                $this->skip($id);
            }
        }

        $this->completion = $reason;
    }

    /**
     * 積んだ動きの前後の差を計画にする(差戻し先・引戻した行は履歴の行に書く値)。
     */
    public function plan(?int $sendBackNodeId = null, ?int $revertedHistoryId = null): CaseRoutePlan
    {
        if ($this->processedNodeId === null || $this->action === null) {
            throw new LogicException('処理したノードの無い計画は作らない');
        }

        $before = [];
        $after = [];

        foreach ($this->states as $id => $state) {
            $original = isset($this->newChildren[$id])
                ? ['state' => WorkflowNodeState::Unreached, 'history' => null, 'targets' => []]
                : ['state' => $this->nodes[$id]->state, 'history' => $this->nodes[$id]->workflow_case_history_id, 'targets' => $this->originalTargets[$id] ?? []];
            $now = ['state' => $state, 'history' => $this->histories[$id], 'targets' => $this->targets[$id]];

            if ($original !== $now || isset($this->newChildren[$id])) {
                $before[$id] = $original;
                $after[$id] = $now;
            }
        }

        return new CaseRoutePlan(
            $this->case, $this->processedNodeId, $this->action, $before, $after, $this->newChildren,
            $this->edges->changedTaken(), $this->completion, $sendBackNodeId, $revertedHistoryId,
            $this->reaches->nodeIds(), $this->reaches->previousColumns(),
        );
    }

    /**
     * 状態を変える(前の確かめに外れたら LogicException)。現在のノードでなくなる・処理済みでなくなるときは、処理対象者と履歴の参照を外す。
     */
    private function move(int $id, bool $allowed, WorkflowNodeState $to): void
    {
        if (! $allowed) {
            throw new LogicException("ノード {$id} を {$this->states[$id]->value} から {$to->value} にできない");
        }

        $this->states[$id] = $to;
        $this->histories[$id] = null;

        if (! $to->isCurrent()) {
            $this->targets[$id] = [];
        }
    }
}
