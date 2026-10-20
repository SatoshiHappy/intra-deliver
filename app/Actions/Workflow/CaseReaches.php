<?php

namespace App\Actions\Workflow;

/**
 * 計画の中の、到達日時と前処理者を書くノードの記録(書くのは {@see CaseReachWriter})。状態の前後の差と別に持つのは、写しから戻すノードを
 * 含めず、到達させ直したノードを含めるため(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節)。
 *
 * @phpstan-type PreviousColumns array{previous_user_id: int, previous_department_id: int|null}
 */
final class CaseReaches
{
    /** @var array<int, true> 到達させたノードの id(仮の負の id を含む) */
    private array $nodeIds = [];

    /** @var PreviousColumns|array{} */
    private array $previous = [];

    public function add(int $id): void
    {
        $this->nodeIds[$id] = true;
    }

    /**
     * この計画の到達は前のノードからの到達(前処理者と起点の組織は同時に書く = 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節)。
     */
    public function fromPrevious(int $previousUserId, ?int $previousDepartmentId): void
    {
        $this->previous = ['previous_user_id' => $previousUserId, 'previous_department_id' => $previousDepartmentId];
    }

    /**
     * @return list<int>
     */
    public function nodeIds(): array
    {
        return array_keys($this->nodeIds);
    }

    /**
     * 到達させたノードに書く前処理者の列(前のノードからの到達でなければ空)。
     *
     * @return PreviousColumns|array{}
     */
    public function previousColumns(): array
    {
        return $this->previous;
    }
}
