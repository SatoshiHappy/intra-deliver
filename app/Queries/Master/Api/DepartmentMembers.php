<?php

namespace App\Queries\Master\Api;

use App\Models\Master\DepartmentMembership;
use App\Queries\Master\MembershipQuery;
use DateTimeInterface;

/**
 * 組織(と役職)に当たるユーザー(docs の 設計/共通マスタ/組織とユーザー.md 9 節「組織に当たるユーザー」「組織 + 役職に当たるユーザー」。規則の 1 か所 =
 * {@see MembershipQuery::memberUserIdsOn()} を呼ぶだけ)と、その逆向きのユーザーが所属する組織(同じ 9 節「ユーザーの所属組織」。
 * {@see MembershipQuery::membershipsOn()} を呼ぶだけ)。判定しない。鍵を取らない。
 *
 * Master の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節の表)。
 */
class DepartmentMembers
{
    public function __construct(private MembershipQuery $memberships) {}

    /**
     * @param  list<int>  $departmentIds  組織(下位の組織は含めない。要るなら呼び手が渡す)
     * @param  int|null  $companyPostId  役職(null = 役職を問わない)
     * @return list<int> d に所属が成り立つ(主所属・兼務)、d に有効なユーザーの id(昇順)。役職ありなら d に成り立つ所属役職がその役職のもの
     */
    public function userIdsOn(array $departmentIds, ?int $companyPostId, DateTimeInterface $date): array
    {
        return $this->memberships->memberUserIdsOn($departmentIds, $companyPostId, $date);
    }

    /**
     * @return list<int> ユーザーが d に所属する(主所属・兼務)組織の id(昇順・重ねない)。下位の組織は含めず、選んだ組織に依らない。
     *                   d に有効でない組織の所属は数えず、無い・d に有効でないユーザーは空
     */
    public function departmentIdsOn(int $userId, DateTimeInterface $date): array
    {
        return array_values($this->memberships->membershipsOn($userId, $date)
            ->map(fn (DepartmentMembership $membership) => $membership->department_id)
            ->unique()
            ->sort()
            ->all());
    }
}
