<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Master\Api\CurrentDepartment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * 案件の詳細の合議の進み具合(docs の 設計/ワークフロー/ルートの深さ/画面.md 7 節)。縦配置が展開中か通過のときだけ作る。承認した人数と必要な人数は
 * 業務処理の合議の判定と同じ 1 か所({@see WorkflowDecisionRule::decide()})、可決したかは親の状態(通過)。行は今の指定の人数 n までの子。
 *
 * @phpstan-import-type Person from WorkflowPeople
 *
 * @phpstan-type CouncilRow array{nodeId: int, name: string, action: string|null, person: array{id: int, code: string, name: string|null, isMe: bool}|null, department: Person|null, at: string|null, stepState: array{state: string, label: string}}
 * @phpstan-type Council array{nodeId: int, name: string, approved: int, required: int, pending: int, passed: bool, rows: list<CouncilRow>}
 */
class CaseCouncils
{
    public function __construct(
        private WorkflowPeople $people,
        private CurrentDepartment $currentDepartment,
    ) {}

    /**
     * @param  Collection<int, WorkflowCaseNode>  $nodes  写した並びのノード(展開の行は親を読み込んである)
     * @param  Collection<int, WorkflowCaseHistory>  $histories  id をキーにした履歴の行
     * @param  array<int, list<int>>  $assignments  今の指定({@see CurrentAssignments::of()}。縦配置が無ければ空でよい)
     * @param  int|null  $requesterUserId  自分の札を付ける人(判定を求める者のユーザー)
     * @return array<int, Council> 縦配置のノードの id → 進み具合(写した並び)
     */
    public function of(WorkflowCase $case, Collection $nodes, Collection $histories, array $assignments, CaseSituation $situation, ?int $requesterUserId, DateTimeInterface $date, string $localeCode): array
    {
        $parents = $nodes->filter(fn (WorkflowCaseNode $node) => $node->kind === WorkflowNodeKind::Parallel && in_array($node->state, WorkflowNodeState::expanded(), true));

        if ($parents->isEmpty()) {
            return [];
        }

        $baseDate = $case->base_date ?? throw new LogicException("申請した案件 {$case->id} に申請基準日が無い");
        $councils = [];

        foreach ($parents as $parent) {
            $assigned = $assignments[$parent->id] ?? [];
            $children = $nodes->filter(fn (WorkflowCaseNode $node) => $node->parent_workflow_case_node_id === $parent->id)->values();
            $councils[$parent->id] = $this->councilOf($parent, $children, $assigned, $histories, $situation, $requesterUserId);
        }

        return $this->withNames($councils, $baseDate, $date, $localeCode);
    }

    /**
     * @param  Collection<int, WorkflowCaseNode>  $children  全部の子(番号の順)
     * @param  list<int>  $assigned  今の指定(指定の順)
     * @param  Collection<int, WorkflowCaseHistory>  $histories
     * @return array{nodeId: int, name: string, approved: int, required: int, pending: int, passed: bool, rows: list<array{nodeId: int, name: string, action: string|null, userId: int|null, isMe: bool, at: string|null, stepState: array{state: string, label: string}}>}
     */
    private function councilOf(WorkflowCaseNode $parent, Collection $children, array $assigned, Collection $histories, CaseSituation $situation, ?int $requesterUserId): array
    {
        $rule = $parent->decision_rule ?? throw new LogicException("縦配置 {$parent->id} に可決の条件が無い");
        $historyOf = fn (WorkflowCaseNode $child) => $child->state === WorkflowNodeState::Done ? $histories->get($child->workflow_case_history_id) : null;
        $decision = $rule->decide($parent->decision_value, count($assigned), $children->mapWithKeys(fn (WorkflowCaseNode $child) => [(int) $child->sequence => $historyOf($child)?->action])->all());
        $counted = $children->filter(fn (WorkflowCaseNode $child) => (int) $child->sequence <= count($assigned))->values();

        return [
            'nodeId' => $parent->id, 'name' => $parent->displayName(), 'approved' => $decision['approvals'], 'required' => $decision['required'],
            'pending' => $counted->filter(fn (WorkflowCaseNode $child) => $child->state->isCurrent())->count(), 'passed' => $parent->state === WorkflowNodeState::Passed,
            'rows' => array_values($counted->map(fn (WorkflowCaseNode $child) => $this->rowOf($child, $historyOf($child), $assigned, $situation, $requesterUserId))->all()),
        ];
    }

    /**
     * 子の行。人 = 処理済みは処理者、現在のノードは処理対象者、ほか(省略・到達していない)は今の指定の同じ番号の人。自分 = 処理済みの行の処理者か、現在のノードの行で
     * 判定を求める者がその処理対象者に当たるときだけ(省略・到達していないの行は自分にしない = 図の人と同じ規則)。
     *
     * @param  list<int>  $assigned
     * @return array{nodeId: int, name: string, action: string|null, userId: int|null, isMe: bool, at: string|null, stepState: array{state: string, label: string}}
     */
    private function rowOf(WorkflowCaseNode $child, ?WorkflowCaseHistory $history, array $assigned, CaseSituation $situation, ?int $requesterUserId): array
    {
        $current = $situation->currentNode($child->id);
        $userId = match (true) {
            $history !== null => (int) $history->user_id,
            $current !== null => $current->targetUserIds[0] ?? null,
            default => $assigned[(int) $child->sequence - 1] ?? null,
        };
        [$state, $label] = WorkflowHomeLabels::step($child->state);

        $isMine = $history !== null || ($current !== null && $situation->isTargetOf($current));

        return [
            'nodeId' => $child->id, 'name' => $child->displayName(), 'action' => $history?->action->value, 'userId' => $userId,
            'isMe' => $isMine && $userId !== null && $userId === $requesterUserId,
            'at' => $history?->created_at?->toIso8601String(), 'stepState' => ['state' => $state, 'label' => $label],
        ];
    }

    /**
     * 子の人の表示と所属(申請基準日のカレント組織 = 主所属。選んだ組織は持たない)を引いて行に入れる。
     *
     * @param  array<int, array{nodeId: int, name: string, approved: int, required: int, pending: int, passed: bool, rows: list<array{nodeId: int, name: string, action: string|null, userId: int|null, isMe: bool, at: string|null, stepState: array{state: string, label: string}}>}>  $councils
     * @return array<int, Council>
     */
    private function withNames(array $councils, DateTimeInterface $baseDate, DateTimeInterface $date, string $localeCode): array
    {
        $userIds = array_values(array_unique(array_filter(array_merge([], ...array_map(fn (array $council) => array_column($council['rows'], 'userId'), array_values($councils))), fn (mixed $id) => is_int($id))));
        $people = $this->people->users($userIds, $date, $localeCode);
        $departmentIds = array_filter(array_combine($userIds, array_map(fn (int $userId) => $this->currentDepartment->departmentIdOn($userId, null, $baseDate), $userIds)), fn (?int $id) => $id !== null);
        $departments = $this->people->departments(array_values(array_unique($departmentIds)), $date, $localeCode);

        return array_map(fn (array $council) => [...$council, 'rows' => array_map(fn (array $row) => [
            'nodeId' => $row['nodeId'],
            'name' => $row['name'],
            'action' => $row['action'],
            'person' => $row['userId'] === null ? null : [...$people[$row['userId']], 'isMe' => $row['isMe']],
            'department' => $row['userId'] !== null && isset($departmentIds[$row['userId']]) ? $departments[$departmentIds[$row['userId']]] : null,
            'at' => $row['at'],
            'stepState' => $row['stepState'],
        ], $council['rows'])], $councils);
    }
}
