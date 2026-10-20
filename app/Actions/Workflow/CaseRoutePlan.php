<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;

/**
 * ノードの動きの計画(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 9 節の 1。書かない)。「どのノードを、どの状態に、どの処理対象者で」を、
 * 変えるノードごとの前後の値で持つ。新しい展開の行は仮の負の id で持ち、書くとき({@see CaseRoutePlanWriter})に本当の id へ付け替える。
 * node_changes(案件の表.md 3.7)と、操作の結果の値の到達したノード(11 節)をここから作る。
 *
 * @phpstan-type NodeValues array{state: WorkflowNodeState, history: int|null, targets: list<int>}
 *
 * @phpstan-import-type PreviousColumns from CaseReaches
 */
final readonly class CaseRoutePlan
{
    /**
     * @param  int  $processedNodeId  操作するノード(この操作の履歴の行を指す処理済みになる)
     * @param  array<int, NodeValues>  $before  変えるノードの id → 前の値(新しい展開の行は到達していない・null・空)
     * @param  array<int, NodeValues>  $after  変えるノードの id → 後の値(操作するノードの history は null = 書くときにこの操作の行を指す)
     * @param  array<int, WorkflowCaseNode>  $newChildren  仮の id → 書いていない展開の行
     * @param  array<int, array<int, array{bool|null, bool|null}>>  $taken  taken を変えた分岐開始の id → 出る辺の id → [前, 後]
     * @param  WorkflowCompletionReason|null  $completion  案件を完了するときの理由
     * @param  int|null  $sendBackNodeId  差戻し先(差戻しだけ。履歴の行に書く)
     * @param  int|null  $revertedHistoryId  引戻した行(引戻しだけ。履歴の行に書き、node_changes を持たない)
     * @param  list<int>  $reachedAtNodeIds  到達日時を書くノード({@see CaseReaches}。{@see self::reachedNodes()} とは別の集まり)
     * @param  PreviousColumns|array{}  $previousColumns  前処理者の列(前のノードからの到達だけ)
     */
    public function __construct(
        public WorkflowCase $case,
        public int $processedNodeId,
        public WorkflowAction $action,
        public array $before,
        public array $after,
        public array $newChildren,
        public array $taken,
        public ?WorkflowCompletionReason $completion,
        public ?int $sendBackNodeId = null,
        public ?int $revertedHistoryId = null,
        public array $reachedAtNodeIds = [],
        public array $previousColumns = [],
    ) {}

    /**
     * 到達したノード(操作の後に現在のノードで、操作の前に現在のノードでなかったもの)と処理対象者(11 節)。
     *
     * @param  array<int, int>  $ids  仮の id → 書いた行の id({@see CaseRoutePlanWriter::addChildren()})
     * @return array<int, list<int>> ノードの id(昇順)→ 処理対象者
     */
    public function reachedNodes(array $ids = []): array
    {
        $reached = [];

        foreach ($this->after as $id => $values) {
            if ($values['state']->isCurrent() && ! $this->before[$id]['state']->isCurrent()) {
                $reached[$ids[$id] ?? $id] = $values['targets'];
            }
        }

        ksort($reached);

        return $reached;
    }

    /**
     * 履歴の行の node_changes(この操作が変えた、操作するノード以外のノードの前の状態。ノードの id の昇順。分岐開始は出る辺の前の taken も持つ)。
     * 引戻しの行は持たない(null。引戻した行を指す = 案件の表.md 3.7)。
     *
     * @param  array<int, int>  $ids  仮の id → 書いた行の id
     * @return list<array<string, mixed>>|null
     */
    public function nodeChanges(array $ids = []): ?array
    {
        if ($this->revertedHistoryId !== null) {
            return null;
        }

        $changes = [];

        foreach ($this->before as $id => $values) {
            if ($id === $this->processedNodeId) {
                continue;
            }

            $nodeId = $ids[$id] ?? $id;
            $change = ['node' => $nodeId, 'state' => $values['state']->value, 'history' => $values['history'], 'targets' => $values['targets']];
            $changes[$nodeId] = isset($this->taken[$id]) ? [...$change, 'taken' => array_map(fn (array $pair) => $pair[0], $this->taken[$id])] : $change;
        }

        ksort($changes);

        return array_values($changes);
    }

    /**
     * 書く順(9 節の 5): 処理済みにするもの → 戻す・省く・通過させるもの → 到達させる・展開するもの。
     *
     * @return list<int> 変えるノードの id
     */
    public function writeOrder(): array
    {
        $rank = fn (WorkflowNodeState $state) => match (true) {
            $state === WorkflowNodeState::Done => 0,
            $state->isCurrent() || $state === WorkflowNodeState::Running => 2,
            default => 1,
        };
        $ids = array_keys($this->after);
        usort($ids, fn (int $a, int $b) => [$rank($this->after[$a]['state']), $a] <=> [$rank($this->after[$b]['state']), $b]);

        return $ids;
    }
}
