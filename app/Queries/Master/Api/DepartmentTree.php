<?php

namespace App\Queries\Master\Api;

use App\Queries\Master\DepartmentTreeQuery;
use App\Queries\Master\OrganizationChartQuery;
use DateTimeInterface;
use LogicException;

/**
 * 組織の上位の組織(docs の 設計/共通マスタ/組織とユーザー.md 9 節「組織の上位組織すべて」。規則の 1 か所 = {@see DepartmentTreeQuery} を呼ぶだけ)と、
 * 組織図の木にある組織の確かめ(規則の 1 か所 = 共通マスタの検索の組織の絞りと同じ {@see OrganizationChartQuery::treeDepartmentsOn()})。
 * 判定しない。鍵を取らない。
 *
 * Master の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節の表)。
 */
class DepartmentTree
{
    public function __construct(
        private DepartmentTreeQuery $trees,
        private OrganizationChartQuery $chart,
    ) {}

    /**
     * @return list<int> d に成り立つ内包を親へたどった組織の id(近い順に根まで。自分を含まない)。d に有効でない組織・d に親の内包が無い組織・無い組織は空
     */
    public function ancestorIdsOn(int $departmentId, DateTimeInterface $date): array
    {
        return $this->trees->ancestorIdsNearestFirst($departmentId, $date);
    }

    /**
     * 渡した組織のうち、d の組織図の木(表示する組織セットの d の木)にあるもの。空の入力は問い合わせずに空。
     *
     * @param  list<int>  $departmentIds
     * @return list<int> 昇順・重ねない。無い組織と木に無い組織は入れない
     *
     * @throws LogicException 並びに int でない値があるとき(呼び手の誤り)
     */
    public function idsInTreeOn(array $departmentIds, DateTimeInterface $date): array
    {
        if ($departmentIds === []) {
            return [];
        }

        self::throwIfNotIntList($departmentIds);
        $tree = array_flip($this->chart->treeDepartmentsOn($date)->modelKeys());
        // キーが並びでなくても拒まない(値だけを読み、最後に並べ直す)
        $ids = array_values(array_unique(array_filter($departmentIds, fn (int $id) => isset($tree[$id]))));
        sort($ids);

        return $ids;
    }

    /**
     * 木の組織と int のまま比べるので、文字列などを受けると戻り値の list<int> が破れる。
     *
     * @param  array<mixed>  $ids
     *
     * @throws LogicException
     */
    private static function throwIfNotIntList(array $ids): void
    {
        if (array_filter($ids, fn (mixed $id) => ! is_int($id)) !== []) {
            throw new LogicException('組織の id は int の並びで渡す');
        }
    }
}
