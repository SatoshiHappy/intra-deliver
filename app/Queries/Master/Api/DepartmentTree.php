<?php

namespace App\Queries\Master\Api;

use App\Queries\Master\DepartmentTreeQuery;
use DateTimeInterface;

/**
 * 組織の上位の組織(docs の 設計/共通マスタ/組織とユーザー.md 9 節「組織の上位組織すべて」。規則の 1 か所 = {@see DepartmentTreeQuery} を呼ぶだけ)。
 * 判定しない。鍵を取らない。
 *
 * Master の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節の表)。
 */
class DepartmentTree
{
    public function __construct(private DepartmentTreeQuery $trees) {}

    /**
     * @return list<int> d に成り立つ内包を親へたどった組織の id(近い順に根まで。自分を含まない)。d に有効でない組織・d に親の内包が無い組織・無い組織は空
     */
    public function ancestorIdsOn(int $departmentId, DateTimeInterface $date): array
    {
        return $this->trees->ancestorIdsNearestFirst($departmentId, $date);
    }
}
