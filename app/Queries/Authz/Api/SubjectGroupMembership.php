<?php

namespace App\Queries\Authz\Api;

use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\User;
use App\Queries\Authz\SubjectGroupMatcher;
use App\Queries\Authz\SubjectQuery;
use App\Queries\Master\MembershipQuery;
use DateTimeInterface;

/**
 * 認可の対象者の本人の判定と展開(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_conditions の「展開」「除外と組み方」)。
 * 判定の意味は {@see SubjectQuery}・{@see SubjectGroupMatcher} が持ち、ここは内部を呼ぶだけ(意味を再実装しない)。認可のリソースは見ない。
 *
 * Authz の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1 の表)。
 * 鍵を取らない。例外を投げない(無い・不正な対象者は合わないとして扱う)。
 */
class SubjectGroupMembership
{
    public function __construct(
        private SubjectQuery $subjects,
        private MembershipQuery $memberships,
    ) {}

    /**
     * 渡した対象者のうち、判定を求める者が d に「対象者に合う」ものの id(渡した順。重ねない)。
     * 無い対象者・不正な対象者(条件 0 件・循環)は合わないとして結果に入れない。
     *
     * @param  list<int>  $subjectGroupIds
     * @return list<int>
     */
    public function matchingIds(Requester $requester, array $subjectGroupIds, DateTimeInterface $date): array
    {
        return $this->subjects->matchingSubjectGroupIds($requester, $subjectGroupIds, $date);
    }

    /**
     * 対象者の展開: d に有効なユーザーそれぞれについて、d のカレント組織の候補(docs の 設計/デモユーザー切替/今の利用者.md 5.5。
     * 無ければ「カレント組織なし」1 つ)の 1 つずつを選んだ判定を求める者(接続元なし)で本人の判定をし、どれかで合えば含める(候補ごとの和)。
     * 接続元が無いので IPv4 アドレスの条件はどの展開にも合わない。用途(policy・selection)は問わない。対象者が無い・不正なら空。
     * 有効なユーザー全員を回す(問い合わせはユーザーと候補の数に比例する)。
     *
     * @return list<int> d に有効なユーザーの id(昇順)
     */
    public function memberUserIdsOn(int $subjectGroupId, DateTimeInterface $date): array
    {
        if (! AuthzSubjectGroup::query()->whereKey($subjectGroupId)->exists()) {
            return [];
        }

        $userIds = User::query()->effectiveOn($date)->orderBy('id')->pluck('id')->map(fn (mixed $id) => intval($id))->all();

        return array_values(array_filter($userIds, fn (int $userId) => $this->matchesWithAnyCandidate($userId, $subjectGroupId, $date)));
    }

    /**
     * 判定を求める者のユーザーが d に有効ならその id、無い・d に有効でなければ null(判定がユーザーなしとして扱うのと同じ = {@see SubjectQuery::subjectOn()})。
     */
    public function effectiveUserId(Requester $requester, DateTimeInterface $date): ?int
    {
        return $this->subjects->subjectOn($requester, $date)->userId;
    }

    /**
     * ユーザーがカレント組織の候補のどれか(候補が無ければカレント組織なし)を選んだとき、対象者に合うか。
     */
    private function matchesWithAnyCandidate(int $userId, int $subjectGroupId, DateTimeInterface $date): bool
    {
        $candidateDepartmentIds = $this->memberships->currentDepartmentCandidatesOn($userId, $date)
            ->map(fn (DepartmentMembership $membership) => $membership->department_id)
            ->all();

        foreach ($candidateDepartmentIds === [] ? [null] : $candidateDepartmentIds as $departmentId) {
            if ($this->subjects->matchingSubjectGroupIds(new Requester($userId, null, $departmentId), [$subjectGroupId], $date) !== []) {
                return true;
            }
        }

        return false;
    }
}
