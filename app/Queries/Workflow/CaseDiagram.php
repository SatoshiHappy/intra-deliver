<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Collection;

/**
 * 案件の詳細のフロー情報(docs の 設計/ワークフロー/ルートの深さ/画面.md 1.2 の図の形・7 節・8 節)。ノードは写した並び({@see WorkflowCaseNode::compareInRoute()})で、
 * 図と表が同じ並びと同じ状態の語({@see WorkflowHomeLabels::step()} = 認可と一覧.md 2 節の写しの表)を読む。表のための処理の種別と日時を持つ。
 * 辺は写した順(id)。名前は表示名({@see WorkflowCaseNode::displayName()} = 案件の操作/README.md 1.3)。
 * awaitingDesignation = 処理の時に指定するノードで、今の指定が無い(指定するノードがまだ指定していない。0 人を指定した = 行が 0 件は含まない) && 到達していない && 案件が未完了(枠の「処理対象者はまだ指定されていません」= 画面.md 8 節)。
 *
 * @phpstan-import-type Person from WorkflowPeople
 * @phpstan-import-type Council from CaseCouncils
 *
 * @phpstan-type DiagramNode array{id: int, code: string|null, name: string, kind: string, parentId: int|null, sequence: int|null, subtitle: string|null, state: string, people: list<array{id: int, code: string, name: string|null, isMe: bool}>, placeholders: list<array{label: string, optional: bool}>, consensus: array{text: string}|null, stepState: array{state: string, label: string}, processed: array{action: string, at: string|null}|null, awaitingDesignation: bool}
 * @phpstan-type DiagramEdge array{from: int, to: int, label: string|null, taken: bool|null}
 */
class CaseDiagram
{
    /**
     * @param  Collection<int, WorkflowCaseNode>  $nodes  写した並びのノード(展開の行は親を読み込んである)
     * @param  Collection<int, WorkflowCaseHistory>  $histories  id をキーにした履歴の行
     * @param  array<int, Person>  $people  処理対象者と処理者の表示
     * @param  array<int, list<int>>  $assignments  今の指定({@see CurrentAssignments::of()}。処理の時に指定するノードが無ければ空でよい)
     * @param  array<int, Council>  $councils  縦配置の進み具合({@see CaseCouncils})
     * @param  int|null  $requesterUserId  判定を求める者のユーザー
     * @return array{nodes: list<DiagramNode>, edges: list<DiagramEdge>}
     */
    public function of(WorkflowCase $case, Collection $nodes, Collection $histories, array $assignments, CaseSituation $situation, array $people, array $councils, ?int $requesterUserId): array
    {
        $names = $nodes->mapWithKeys(fn (WorkflowCaseNode $node) => [$node->id => $node->displayName()])->all();
        $isActive = $case->state !== WorkflowCaseState::Completed;
        $awaiting = fn (WorkflowCaseNode $node) => $isActive && $node->designator_workflow_case_node_id !== null && $node->state === WorkflowNodeState::Unreached && ! array_key_exists($node->id, $assignments);

        return [
            'nodes' => array_values($nodes->map(fn (WorkflowCaseNode $node) => $this->nodeOf($node, $histories, $situation, $people, $names, $councils[$node->id] ?? null, $requesterUserId, $awaiting($node)))->all()),
            'edges' => array_values(WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->orderBy('id')->get()->map(fn (WorkflowCaseEdge $edge) => [
                'from' => (int) $edge->from_workflow_case_node_id,
                'to' => (int) $edge->to_workflow_case_node_id,
                'label' => $edge->label,
                'taken' => $edge->taken,
            ])->all()),
        ];
    }

    /**
     * @param  Collection<int, WorkflowCaseHistory>  $histories
     * @param  array<int, Person>  $people
     * @param  array<int, string>  $names  ノードの id → 表示名
     * @param  Council|null  $council
     * @return DiagramNode
     */
    private function nodeOf(WorkflowCaseNode $node, Collection $histories, CaseSituation $situation, array $people, array $names, ?array $council, ?int $requesterUserId, bool $awaitingDesignation): array
    {
        $history = $node->state === WorkflowNodeState::Done ? $histories->get($node->workflow_case_history_id) : null;
        $designatorId = $node->designator_workflow_case_node_id;
        [$state, $label] = WorkflowHomeLabels::step($node->state);

        return [
            'id' => $node->id,
            'code' => $node->code,
            'name' => $names[$node->id],
            'kind' => $node->kind->value,
            'parentId' => $node->parent_workflow_case_node_id === null ? null : (int) $node->parent_workflow_case_node_id,
            'sequence' => $node->sequence,
            'subtitle' => StepDescriptions::subtitle($node->kind, $designatorId === null ? null : ($names[(int) $designatorId] ?? null)),
            'state' => $node->state->value,
            'people' => $this->peopleOf($node, $history, $situation, $people, $requesterUserId),
            'placeholders' => [],
            'consensus' => $node->kind === WorkflowNodeKind::Parallel ? ['text' => $this->consensusOf($node, $council)] : null,
            'stepState' => ['state' => $state, 'label' => $label],
            'processed' => $history === null ? null : ['action' => $history->action->value, 'at' => $history->created_at?->toIso8601String()],
            'awaitingDesignation' => $awaitingDesignation,
        ];
    }

    /**
     * 人 = 現在のノードは処理対象者(isMe = 判定を求める者がそのノードの処理対象者に当たり、その人)、処理済みのノードは処理者(isMe = 判定を求める者)、ほかは無し。
     *
     * @param  array<int, Person>  $people
     * @return list<array{id: int, code: string, name: string|null, isMe: bool}>
     */
    private function peopleOf(WorkflowCaseNode $node, ?WorkflowCaseHistory $history, CaseSituation $situation, array $people, ?int $requesterUserId): array
    {
        if ($history !== null) {
            return [[...$people[(int) $history->user_id], 'isMe' => (int) $history->user_id === $requesterUserId]];
        }

        $current = $situation->currentNode($node->id);

        if ($current === null) {
            return [];
        }

        $me = $situation->isTargetOf($current) ? $requesterUserId : null;

        return array_map(fn (int $userId) => [...$people[$userId], 'isMe' => $userId === $me], $current->targetUserIds);
    }

    /**
     * 縦配置の完了の条件の 1 行(画面.md 1.2: 進み具合があれば「完了の条件 承認 {k} 人　現在 {a} 人」、無ければ定義の条件)。
     *
     * @param  Council|null  $council
     */
    private function consensusOf(WorkflowCaseNode $node, ?array $council): string
    {
        if ($council !== null) {
            return "完了の条件 承認 {$council['required']} 人　現在 {$council['approved']} 人";
        }

        return match ($node->decision_rule) {
            WorkflowDecisionRule::Count => "完了の条件 承認 {$node->decision_value} 人",
            WorkflowDecisionRule::Ratio => "完了の条件 承認 {$node->decision_value}% 以上",
            default => '完了の条件 全員が承認',
        };
    }
}
