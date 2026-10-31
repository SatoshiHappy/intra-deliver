<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowCaseNode;
use DateTimeInterface;
use LogicException;

/**
 * 承認のダイアログの「指定するステップ」の欄(docs の 設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md 6 節、案件の操作/処理対象者.md 5.2): 指定するノード X ごとに、
 * X が指定するノード Y の名前・種類の語・人数の範囲・可決の条件(縦配置だけ。規則・値と、人数 1〜最大ごとの必要な数 k の表 `required` = 添字 i が人数 i + 1 の k。画面は式を持たずこの表を引く)と、初期値 = X の最新の承認の指定(引戻されたかを問わない =
 * {@see CurrentAssignments::latestOf()}。差戻しの後も引戻しの後も前の指定から始める。無い・0 人なら空)。計画の今の指定とは別。
 * 鍵を取らない。
 *
 * @phpstan-import-type Person from WorkflowPeople
 *
 * @phpstan-type Designation array{nodeId: int, name: string, kind: string, kindLabel: string, min: int, max: int, decision: array{rule: string, value: int|null, required: list<int>}|null, initial: list<Person>}
 */
class CaseDesignations
{
    /** 指定されるノードの種類の語(画面の見出し「指定するステップ　{種類の語} {名前}」)。画面の図の枠の語 `FRAME_KIND_LABELS`(WorkflowDiagram.tsx)はこの語の写しで、tests/Architecture の写しを結ぶテストが共通のキーの一致を見る */
    public const KIND_LABELS = ['approve' => '承認', 'serial' => '横配置', 'parallel' => '縦配置'];

    public function __construct(
        private CurrentAssignments $assignments,
        private WorkflowPeople $people,
    ) {}

    /**
     * @param  list<int>  $designatorIds  指定するノード X の候補(処理のダイアログを開けるノード)
     * @return array<int, list<Designation>> X の id → 指定されるノード(写した並び = 位置の順。X が指定しなければ空)
     */
    public function of(int $caseId, array $designatorIds, DateTimeInterface $date, string $localeCode): array
    {
        $designated = $designatorIds === [] ? [] : WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->whereIn('designator_workflow_case_node_id', $designatorIds)->orderBy('position')->orderBy('id')->get()->all();
        $current = $designated === [] ? [] : $this->assignments->latestOf($caseId);
        $people = $this->people->users(array_merge([], ...array_values($current)), $date, $localeCode);
        $result = array_fill_keys($designatorIds, []);

        foreach ($designated as $node) {
            $max = $node->max_assignees ?? throw new LogicException("指定されるノード {$node->id} に人数が無い");
            $result[(int) $node->designator_workflow_case_node_id][] = [
                'nodeId' => $node->id,
                'name' => $node->name,
                'kind' => $node->kind->value,
                'kindLabel' => self::KIND_LABELS[$node->kind->value] ?? throw new LogicException("指定されるノード {$node->id} の種類 {$node->kind->value} に語が無い"),
                'min' => $node->min_assignees ?? throw new LogicException("指定されるノード {$node->id} に人数が無い"),
                'max' => $max,
                'decision' => $node->kind === WorkflowNodeKind::Parallel && $node->decision_rule !== null ? $this->decisionOf($node->decision_rule, $node->decision_value, $max) : null,
                'initial' => array_map(fn (int $userId) => $people[$userId], $current[$node->id] ?? []),
            ];
        }

        return $result;
    }

    /**
     * 縦配置の可決の条件と、人数 1〜$max ごとの必要な数 k(ルートの深さ/案件の操作/README.md 2.5。式は {@see WorkflowDecisionRule::required()} の 1 か所)。
     *
     * @return array{rule: string, value: int|null, required: list<int>}
     */
    private function decisionOf(WorkflowDecisionRule $rule, ?int $value, int $max): array
    {
        return [
            'rule' => $rule->value,
            'value' => $value,
            'required' => array_map(fn (int $count) => $rule->required($value, $count), $max >= 1 ? range(1, $max) : []),
        ];
    }
}
