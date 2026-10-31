<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Records\Api\RecordConditions;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * 案件の詳細のフロー情報(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.2 の図の形・7 節・8 節)。
 * ノードは写した並び({@see WorkflowCaseNode::compareInRoute()})で、
 * 図と表が同じ並びと同じ状態の語({@see WorkflowHomeLabels::detailStep()} = 認可と一覧.md 2 節の写しの表。保留中は state が hold)を読む。表のための処理の種別と日時を持つ。
 * 辺は写した順(id)。名前は表示名({@see WorkflowCaseNode::displayName()} = 案件の操作/README.md 1.3)。辺の条件の文は、写した条件を
 * コンテンツの業務モジュールの今の定義で作る({@see RecordConditions::describe()}。定義の図と同じ)。
 * awaitingDesignation = 処理の時に指定するノードで、今の指定が無い(指定するノードがまだ指定していない。0 人を指定した = 行が 0 件は含まない) && 到達していない && 案件が未完了(枠の文 = docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 8 節。文言の持ち主は WorkflowDiagram.tsx)。
 * フロー情報の表の処理担当者 targets(現在のノードの処理対象者)と処理実行者(processed の actor・executor)=
 * 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節。
 *
 * @phpstan-import-type Person from WorkflowPeople
 * @phpstan-import-type Council from CaseCouncils
 *
 * @phpstan-type MarkedPerson array{id: int, code: string, name: string|null, isMe: bool}
 * @phpstan-type Processed array{action: string, at: string|null, actor: Person, executor: Person|null}
 * @phpstan-type DiagramNode array{id: int, code: string|null, name: string, kind: string, parentId: int|null,
 *     sequence: int|null, subtitle: string|null, state: string, people: list<MarkedPerson>,
 *     targets: list<MarkedPerson>, placeholders: list<array{label: string, optional: bool}>,
 *     consensus: array{text: string}|null,
 *     stepState: array{state: string, label: string}, processed: Processed|null, awaitingDesignation: bool}
 * @phpstan-type DiagramEdge array{from: int, to: int, label: string|null, conditionText: string|null, taken: bool|null}
 */
class CaseDiagram
{
    public function __construct(private RecordConditions $conditions) {}

    /**
     * @param  Collection<int, WorkflowCaseNode>  $nodes  写した並びのノード(展開の行は親を読み込んである)
     * @param  Collection<int, WorkflowCaseHistory>  $histories  id をキーにした履歴の行
     * @param  array<int, Person>  $people  処理対象者・処理者と今の指定の人の表示
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
        $moduleId = self::moduleIdOf($case);
        // 展開の行の今の指定の同じ番号の人(親の指定の sequence 番目。指定が無いか番号が指定の数を超えれば null)
        $plannedOf = fn (WorkflowCaseNode $node): ?int => $node->parent_workflow_case_node_id === null
            ? null
            : $assignments[(int) $node->parent_workflow_case_node_id][(int) $node->sequence - 1] ?? null;

        return [
            'nodes' => array_values($nodes->map(fn (WorkflowCaseNode $node) => $this->nodeOf($node, $histories, $situation, $people, $names, $councils[$node->id] ?? null, $requesterUserId, $awaiting($node), $plannedOf($node)))->all()),
            'edges' => array_values(WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->orderBy('id')->get()->map(fn (WorkflowCaseEdge $edge) => [
                'from' => (int) $edge->from_workflow_case_node_id,
                'to' => (int) $edge->to_workflow_case_node_id,
                'label' => $edge->label,
                'conditionText' => $edge->condition === null
                    ? null : $this->conditions->describe($moduleId, $edge->condition),
                'taken' => $edge->taken,
            ])->all()),
        ];
    }

    /**
     * 案件のフローのコンテンツの業務モジュール(フローとコンテンツを読み込んだ案件。読み込んでいなければ呼び手の誤り)。
     */
    private static function moduleIdOf(WorkflowCase $case): int
    {
        $content = CaseRows::flowOf($case)->workflowContent
            ?? throw new LogicException("案件 {$case->id} のフローのコンテンツを読み込んでいない");

        return $content->record_module_id;
    }

    /**
     * @param  Collection<int, WorkflowCaseHistory>  $histories
     * @param  array<int, Person>  $people
     * @param  array<int, string>  $names  ノードの id → 表示名
     * @param  Council|null  $council
     * @return DiagramNode
     */
    private function nodeOf(WorkflowCaseNode $node, Collection $histories, CaseSituation $situation, array $people, array $names, ?array $council, ?int $requesterUserId, bool $awaitingDesignation, ?int $plannedUserId): array
    {
        $history = $node->state === WorkflowNodeState::Done ? $histories->get($node->workflow_case_history_id) : null;
        $designatorId = $node->designator_workflow_case_node_id;
        [$state, $label] = WorkflowHomeLabels::detailStep($node->state);

        return [
            'id' => $node->id,
            'code' => $node->code,
            'name' => $names[$node->id],
            'kind' => $node->kind->value,
            'parentId' => $node->parent_workflow_case_node_id === null ? null : (int) $node->parent_workflow_case_node_id,
            'sequence' => $node->sequence,
            'subtitle' => StepDescriptions::designatorSubtitle($node->kind, $designatorId === null ? null : ($names[(int) $designatorId] ?? null)),
            'state' => $node->state->value,
            'people' => $this->peopleOf($node, $history, $situation, $people, $requesterUserId, $plannedUserId),
            'targets' => $this->targetsOf($node, $situation, $people, $requesterUserId),
            'placeholders' => [],
            'consensus' => $node->kind === WorkflowNodeKind::Parallel ? ['text' => $this->consensusOf($node, $council)] : null,
            'stepState' => ['state' => $state, 'label' => $label],
            'processed' => $history === null ? null : self::processedOf($history, $people),
            'awaitingDesignation' => $awaitingDesignation,
        ];
    }

    /**
     * 人 = 現在のノードは処理対象者(isMe = 判定を求める者がそのノードの処理対象者に当たり、その人)、処理済みのノードは処理者(isMe = 判定を求める者)、
     * どちらでもない展開の行(省略・到達していない)は今の指定の同じ番号の人 plannedUserId(isMe はいつも偽 = 合議の進み具合の行と同じ規則 {@see CaseCouncils})、ほかは無し。
     *
     * @param  array<int, Person>  $people
     * @return list<array{id: int, code: string, name: string|null, isMe: bool}>
     */
    private function peopleOf(WorkflowCaseNode $node, ?WorkflowCaseHistory $history, CaseSituation $situation, array $people, ?int $requesterUserId, ?int $plannedUserId): array
    {
        if ($history !== null) {
            return [[...$people[(int) $history->user_id], 'isMe' => (int) $history->user_id === $requesterUserId]];
        }

        $current = $situation->currentNode($node->id);

        if ($current === null) {
            return $plannedUserId === null ? [] : [[...$people[$plannedUserId], 'isMe' => false]];
        }

        $me = $situation->isTargetOf($current) ? $requesterUserId : null;

        return array_map(fn (int $userId) => [...$people[$userId], 'isMe' => $userId === $me], $current->targetUserIds);
    }

    /**
     * 処理担当者 = 現在のノードの処理対象者の行の人(isMe = 判定を求める者その人)。現在のノードでなければ空。
     *
     * @param  array<int, Person>  $people
     * @return list<MarkedPerson>
     */
    private function targetsOf(
        WorkflowCaseNode $node,
        CaseSituation $situation,
        array $people,
        ?int $requesterUserId,
    ): array {
        $current = $situation->currentNode($node->id);

        return $current === null ? [] : array_map(
            fn (int $userId) => [...$people[$userId], 'isMe' => $userId === $requesterUserId],
            $current->targetUserIds,
        );
    }

    /**
     * 処理済みにした履歴の行の種別・日時と処理実行者の表示(実行者は代理で処理したときだけ = 設計/ワークフロー/案件の運用/画面/README.md の 8.2 節)。
     *
     * @param  array<int, Person>  $people  処理者と実行者を含む
     * @return Processed
     */
    private static function processedOf(WorkflowCaseHistory $history, array $people): array
    {
        $executorId = $history->executor_user_id;

        return [
            'action' => $history->action->value,
            'at' => $history->created_at?->toIso8601String(),
            'actor' => $people[(int) $history->user_id],
            'executor' => $executorId === null ? null : $people[(int) $executorId],
        ];
    }

    /**
     * 縦配置の完了の条件の札(図と意匠とE2E.md 8 節: 進み具合があれば「承認 {a} / {k} 人」= 可決の条件によらず同じ形。k は条件から決めた必要な人数。
     * 進み具合が無い(到達していない・省略)なら定義の条件。定義の条件の文は定義の図と同じ {@see StepDescriptions::consensus()})。
     *
     * @param  Council|null  $council
     */
    private function consensusOf(WorkflowCaseNode $node, ?array $council): string
    {
        if ($council !== null) {
            return "承認 {$council['approved']} / {$council['required']} 人";
        }

        return StepDescriptions::consensus($node->decision_rule, $node->decision_value);
    }
}
