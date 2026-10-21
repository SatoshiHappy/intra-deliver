<?php

namespace App\Queries\Records;

use App\Queries\Authz\Api\Requester;
use App\Queries\Master\Api\CurrentDepartment;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;

/**
 * コメントの宛先にできる人(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 2 節・認可と初期データ.md の 1.2 節): ほかのユーザーが
 * そのレコードを閲覧できるか({@see RecordViewAccess})。その人の今日のカレント組織の候補ごとに、接続元なし・選んだ組織 = その候補で判定し、
 * どれかで真なら真(兼務の組織を選べば開ける人も含める)。候補が無い人は組織なしで 1 回判定する。
 * 今日に有効なユーザーだけを渡す(有効でないユーザーは判定でユーザーなしとして扱われ、ゲストに読めるレコードが通ってしまう)。
 */
class RecordMentionViewers
{
    public function __construct(
        private RecordViewAccess $access,
        private CurrentDepartment $departments,
    ) {}

    /**
     * @param  list<int>  $userIds  今日に有効なユーザー
     * @return list<int> 渡した順のうち、閲覧できる人
     */
    public function among(string $moduleCode, int $recordId, array $userIds, DateTimeInterface $date): array
    {
        return array_values(array_filter(
            $userIds,
            fn (int $userId): bool => $this->canView($moduleCode, $recordId, $userId, $date),
        ));
    }

    private function canView(string $moduleCode, int $recordId, int $userId, DateTimeInterface $date): bool
    {
        $candidates = $this->departments->candidateDepartmentIdsOn($userId, $date);

        foreach ($candidates === [] ? [null] : $candidates as $departmentId) {
            if ($this->access->canView(new Requester($userId, null, $departmentId), $moduleCode, $recordId, $date)) {
                return true;
            }
        }

        return false;
    }
}
