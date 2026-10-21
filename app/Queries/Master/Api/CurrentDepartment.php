<?php

namespace App\Queries\Master\Api;

use App\Queries\Master\MembershipQuery;
use DateTimeInterface;

/**
 * ユーザーの d のカレント組織(docs の 設計/デモユーザー切替/今の利用者.md 5.5。規則の 1 か所 = {@see MembershipQuery::currentMembershipOn()} を呼ぶだけ)。
 * 候補は d に成り立つ既定の組織セットの所属。選んだ組織が候補に無ければ主所属へ落ちる(例外にしない)。判定しない。鍵を取らない。
 *
 * Master の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1 の表)。
 */
class CurrentDepartment
{
    public function __construct(private MembershipQuery $memberships) {}

    /**
     * @param  int|null  $selectedDepartmentId  利用者が選んだ組織(departments.id)。null = 選んでいない
     * @return int|null カレント組織の id。無い・d に有効でないユーザー、候補の所属が無いユーザーは null
     */
    public function departmentIdOn(int $userId, ?int $selectedDepartmentId, DateTimeInterface $date): ?int
    {
        return $this->memberships->currentMembershipOn($userId, $selectedDepartmentId, $date)?->department_id;
    }

    /**
     * ユーザーの d のカレント組織の候補(選び方の入力。規則の 1 か所 = {@see MembershipQuery::currentDepartmentCandidatesOn()})。
     * 兼務の組織を選べば開ける人まで含めて判定したいとき(ほかのユーザーについての判定)に、候補ごとに判定を求める者を作る。
     *
     * @return list<int> 候補の組織の id(昇順。重ねない)。無い・d に有効でないユーザー、候補の所属が無いユーザーは空
     */
    public function candidateDepartmentIdsOn(int $userId, DateTimeInterface $date): array
    {
        $candidates = $this->memberships->currentDepartmentCandidatesOn($userId, $date);
        $ids = $candidates->pluck('department_id')->unique()->sort()->values();

        return array_values(array_map('intval', $ids->all()));
    }
}
