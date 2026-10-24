<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Queries\Master\Api\MasterNames;
use DateTimeInterface;
use Illuminate\Support\Collection;
use LogicException;

/**
 * 詳細の確認の面(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節。状態の式 = 案件の操作/README.md の 1.5 節)。
 * 確認する人を持つノードごとに、確認対象者の行の人と状態・確認日時を並べる。確認が一度も開いていない(行が無い)ノードは、
 * 確認を開く業務処理と同じ口 {@see SubjectGroupMembership::memberUserIdsOn()} で申請基準日に展開した人を表示だけに出す(行を書かない)。
 * 展開は同じ対象者を 1 回だけ引く(問い合わせは確認する人のノードの数でなく対象者の数に比例する)。
 *
 * @phpstan-import-type Person from WorkflowPeople
 *
 * @phpstan-type ConfirmationPerson array{person: Person, department: string|null, isMe: bool, status: string,
 *     confirmedAt: string|null}
 * @phpstan-type Confirmation array{nodeId: int, stepName: string, people: list<ConfirmationPerson>}
 */
class CaseConfirmationPanel
{
    public function __construct(
        private CaseConfirmations $confirmations,
        private SubjectGroupMembership $membership,
        private WorkflowPeople $people,
        private MasterNames $names,
    ) {}

    /**
     * @param  Collection<int, WorkflowCaseNode>  $nodes  写した並びのノード(展開の行は親を読み込んである)
     * @param  int|null  $userId  判定を求める者のユーザー(自分の札)
     * @return list<Confirmation>
     */
    public function of(
        WorkflowCase $case,
        Collection $nodes,
        ?int $userId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $confirmNodes = $nodes->filter(fn (WorkflowCaseNode $node) => $node->confirm_authz_subject_group_id !== null);

        if ($confirmNodes->isEmpty()) {
            return [];
        }

        $states = $this->confirmations->statesOf($case);
        $latest = $this->confirmations->latestConfirmedAtOf($case);
        $confirmTargets = $this->confirmTargetsOf($case, $confirmNodes, $states);
        $userIds = array_values(array_unique(array_merge([], ...array_values($confirmTargets))));
        $people = $this->people->users($userIds, $date, $localeCode);
        $departments = $this->names->mainDepartmentNames($userIds, $date, $localeCode);
        $panel = [];

        foreach ($confirmNodes as $node) {
            $person = fn (int $id) => [
                'person' => $people[$id],
                'department' => $departments[$id] ?? null,
                'isMe' => $id === $userId,
                'status' => ($states[$node->id][$id] ?? CaseConfirmations::stateOf($case, $node, false))->value,
                'confirmedAt' => $latest[$node->id][$id] ?? null,
            ];
            $rows = array_map($person, $confirmTargets[$node->id]);
            $panel[] = ['nodeId' => $node->id, 'stepName' => $node->displayName(), 'people' => $rows];
        }

        return $panel;
    }

    /**
     * ノードごとの確認対象者(行があればその人、無ければ申請基準日の展開。どちらも昇順)。
     *
     * @param  Collection<int, WorkflowCaseNode>  $confirmNodes
     * @param  array<int, array<int, mixed>>  $states  確認対象者の行を持つノードの id → ユーザーの id → 状態
     * @return array<int, list<int>> ノードの id → ユーザーの id
     *
     * @throws LogicException 申請した案件に申請基準日が無いとき
     */
    private function confirmTargetsOf(WorkflowCase $case, Collection $confirmNodes, array $states): array
    {
        $expanded = [];
        $confirmTargets = [];

        foreach ($confirmNodes as $node) {
            if (isset($states[$node->id])) {
                $confirmTargets[$node->id] = array_keys($states[$node->id]);

                continue;
            }

            $groupId = (int) $node->confirm_authz_subject_group_id;
            $baseDate = $case->base_date ?? throw new LogicException("申請した案件 {$case->id} に申請基準日が無い");
            $expanded[$groupId] ??= $this->membership->memberUserIdsOn($groupId, $baseDate);
            $confirmTargets[$node->id] = $expanded[$groupId];
        }

        return $confirmTargets;
    }
}
