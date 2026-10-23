<?php

namespace App\Queries\Authz;

use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Master\DepartmentTreeQuery;
use App\Queries\Master\MembershipQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * 認可の対象者に、判定を求める者が d に合うか(docs の 設計/共通マスタ/ロールと認可/対象者.md。条件の相手ごとの合い方もそこ)。
 *
 * 組織と役職(所属から来る事実)はカレント組織の所属 1 つだけから作る(カレント組織の決まり方 = docs の 設計/デモユーザー切替/今の利用者.md 5.5)。
 * 事実の組み立ては 1 人の判定と対象者の展開で 1 か所({@see self::requesterFactsFor()})。
 * 子の対象者・除外と式の組み方・「対象者に合う」は {@see SubjectGroupMatcher}。
 */
class SubjectQuery
{
    /**
     * 組み立てた判定を求める者の d の事実(キー = ユーザー ID・選んだ組織 ID・接続元の IPv4 アドレス・基準日の値)。
     * インスタンスの中だけで覚える。singleton・scoped に束縛しない(要求をまたいで覚えない = docs の 設計/メニュー/表示.md 3.1 の 8)。
     *
     * @var array<string, Subject>
     */
    private array $requesterFacts = [];

    public function __construct(
        private MembershipQuery $memberships,
        private DepartmentTreeQuery $departmentTree,
        private RoleQuery $roles,
    ) {}

    /**
     * 判定を求める者が d に、対象者のいずれかに「対象者に合う」か({@see SubjectGroupMatcher})。対象者が無ければ合わない。
     *
     * @param  array<int, int>  $subjectGroupIds
     */
    public function matchesAny(Requester $requester, array $subjectGroupIds, DateTimeInterface $date): bool
    {
        return $this->matchingSubjectGroupIds($requester, $subjectGroupIds, $date) !== [];
    }

    /**
     * 渡した対象者のうち、判定を求める者が d に「対象者に合う」もの({@see SubjectGroupMatcher})の id(渡した順。重ねない)。
     * 空の入力なら空(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.1)。
     *
     * @param  array<int, int>  $subjectGroupIds
     * @return list<int>
     */
    public function matchingSubjectGroupIds(Requester $requester, array $subjectGroupIds, DateTimeInterface $date): array
    {
        if ($subjectGroupIds === []) {
            return [];
        }

        $subjectGroups = $this->reachableSubjectGroups($subjectGroupIds, $date);
        $matcher = new SubjectGroupMatcher($this->subjectOn($requester, $date), $subjectGroups);

        $matching = [];

        foreach (array_unique($subjectGroupIds) as $subjectGroupId) {
            $subjectGroup = $subjectGroups->get($subjectGroupId);

            if ($subjectGroup !== null && $matcher->matches($subjectGroup)) {
                $matching[] = $subjectGroupId;
            }
        }

        return $matching;
    }

    /**
     * 判定を求める者の d の事実。どれも d に成り立つ関係だけから作る。ユーザーが d に有効でない・存在しないときは、ユーザーなし(事実は空)。
     * 所属から来る事実(組織・その上位と下位・役職)は、カレント組織の所属 1 つだけから作る(カレント組織が無ければ空)。
     * 同じインスタンスで同じ判定を求める者(3 つの値)と基準日には、1 回だけ組み立てて使い回す(1 回の表示で判定を数十回行う画面のため =
     * docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.2)。
     */
    public function subjectOn(Requester $requester, DateTimeInterface $date): Subject
    {
        $key = implode('|', [$requester->userId ?? '', $requester->selectedDepartmentId ?? '', $requester->ipv4Address ?? '', $date->format('Y-m-d')]);

        return $this->requesterFacts[$key] ??= $this->buildSubjectOn($requester, $date);
    }

    /**
     * 渡したユーザーのうち、対象者に「対象者に合う」者の id(昇順。重ねない。意味は docs の 設計/共通マスタ/ロールと認可/対象者.md の「展開」)。
     * 事実をまとめて組み立てるので、問い合わせの数はユーザーの数によらない({@see self::subjectOn()} の覚えは使わない)。
     * d に有効でないユーザーは先に除く(判定ではユーザーなしになり、ゲストの条件に合ってしまうため)。
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function matchingUserIdsAmong(int $subjectGroupId, array $userIds, DateTimeInterface $date): array
    {
        $subjectGroups = $this->reachableSubjectGroups([$subjectGroupId], $date);
        $subjectGroup = $subjectGroups->get($subjectGroupId);

        if ($subjectGroup === null) {
            return [];
        }

        $candidates = $this->candidatesOfEffectiveUsers($userIds, $date);
        $matching = [];

        $requesters = $this->candidateRequesters($candidates);

        foreach ($this->requesterFactsFor($requesters, $candidates, $date) as $requesterFacts) {
            $matcher = new SubjectGroupMatcher($requesterFacts, $subjectGroups);

            if ($matcher->matches($subjectGroup)) {
                $matching[] = $requesterFacts->userId ?? throw new LogicException('展開の判定を求める者はユーザーなしにならない');
            }
        }

        sort($matching);
        $unique = array_unique($matching);

        return array_values($unique);
    }

    /**
     * {@see self::subjectOn()} の中身。1 人で {@see self::requesterFactsFor()} を呼ぶ。
     */
    private function buildSubjectOn(Requester $requester, DateTimeInterface $date): Subject
    {
        $candidates = $this->candidatesOfEffectiveUsers($requester->userId === null ? [] : [$requester->userId], $date);

        return $this->requesterFactsFor([$requester], $candidates, $date)[0];
    }

    /**
     * 判定を求める者それぞれの d の事実(渡した順)。ユーザーが候補の表に無い(d に有効でない・存在しない)者はユーザーなし(事実は空)。
     * 上位と下位の組織・ロールは、全員の分をまとめて読む(問い合わせを人数で増やさない)。
     *
     * @param  list<Requester>  $requesters
     * @param  array<int, Collection<int, DepartmentMembership>>  $candidates  d に有効なユーザー → カレント組織の候補
     * @return list<Subject>
     */
    private function requesterFactsFor(array $requesters, array $candidates, DateTimeInterface $date): array
    {
        $userIds = array_map(
            fn (Requester $requester) => $this->effectiveUserIdOf($requester, $candidates),
            $requesters,
        );
        $memberships = array_map(
            fn (Requester $requester, ?int $userId) => $userId === null
                ? null
                : $this->memberships->currentMembershipAmong($candidates[$userId], $requester->selectedDepartmentId),
            $requesters,
            $userIds,
        );
        $currentMemberships = array_filter($memberships);
        $currentDepartmentIds = array_map(
            fn (DepartmentMembership $membership) => $membership->department_id,
            $currentMemberships,
        );
        $departmentIds = array_values(array_unique($currentDepartmentIds));
        $upper = $this->departmentTree->ancestorsOfEach($departmentIds, $date);
        $lower = $this->departmentTree->descendantsOfEach($departmentIds, $date);
        $roleIds = $this->roles->roleIdsOfEach(array_keys($candidates), $date);

        return array_map(fn (Requester $requester, ?int $userId, ?DepartmentMembership $membership) => new Subject(
            userId: $userId,
            ipv4Address: $requester->ipv4Address,
            date: $date,
            departmentIds: $membership === null ? [] : [$membership->department_id],
            upperDepartmentIds: $this->reachedDepartmentIds($upper, $membership),
            lowerDepartmentIds: $this->reachedDepartmentIds($lower, $membership),
            companyPosts: $this->companyPostsOf($membership),
            roleIds: $userId === null ? [] : $roleIds[$userId],
        ), $requesters, $userIds, $memberships);
    }

    /**
     * 展開で判定する者(docs の 設計/共通マスタ/ロールと認可/対象者.md の「展開」)。
     *
     * @param  array<int, Collection<int, DepartmentMembership>>  $candidates  d に有効なユーザー → カレント組織の候補
     * @return list<Requester>
     */
    private function candidateRequesters(array $candidates): array
    {
        $requesters = [];

        foreach ($candidates as $userId => $memberships) {
            $selectable = $memberships->map(fn (DepartmentMembership $candidate) => $candidate->department_id)->all();

            foreach ($selectable === [] ? [null] : $selectable as $departmentId) {
                $requesters[] = new Requester($userId, null, $departmentId);
            }
        }

        return $requesters;
    }

    /**
     * 候補の表のキーは d に有効なユーザーだけなので、有効かどうかはここで決まる(無ければ null = ユーザーなし)。
     *
     * @param  array<int, Collection<int, DepartmentMembership>>  $candidates
     */
    private function effectiveUserIdOf(Requester $requester, array $candidates): ?int
    {
        return $requester->userId !== null && array_key_exists($requester->userId, $candidates)
            ? $requester->userId
            : null;
    }

    /**
     * 渡したユーザーのうち d に有効なものがキー(候補が無ければ空)。d に有効でないユーザーは判定でユーザーなしなので、候補を読まずに外す。
     *
     * @param  list<int>  $userIds
     * @return array<int, Collection<int, DepartmentMembership>>
     */
    private function candidatesOfEffectiveUsers(array $userIds, DateTimeInterface $date): array
    {
        if ($userIds === []) {
            return [];
        }

        $effectiveUserIds = User::query()
            ->whereRaw('users.id = ANY (?::bigint[])', ['{'.implode(',', $userIds).'}'])
            ->effectiveOn($date)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id) => intval($id))
            ->all();

        return $this->memberships->currentDepartmentCandidatesAmongOn(array_values($effectiveUserIds), $date);
    }

    /**
     * 対象者と、そこから子の対象者をたどれるすべての対象者(id をキーに)。子の対象者の循環でも終わるように、たどったものを重ねない(UNION)。
     * 条件の相手(user・department・companyPost)は d に有効なものだけを読み込む(有効でない相手は null になり、{@see Subject} が合わないと判定する)。
     *
     * @param  array<int, int>  $subjectGroupIds
     * @return Collection<int, AuthzSubjectGroup>
     */
    private function reachableSubjectGroups(array $subjectGroupIds, DateTimeInterface $date): Collection
    {
        return AuthzSubjectGroup::query()
            ->whereRaw(<<<'SQL'
                authz_subject_groups.id IN (
                    WITH RECURSIVE reachable (id) AS (
                        SELECT authz_subject_groups.id FROM authz_subject_groups WHERE authz_subject_groups.id = ANY (?::bigint[])
                        UNION
                        SELECT authz_subject_conditions.child_authz_subject_group_id FROM authz_subject_conditions
                        JOIN reachable ON authz_subject_conditions.authz_subject_group_id = reachable.id
                        WHERE authz_subject_conditions.child_authz_subject_group_id IS NOT NULL
                    )
                    SELECT reachable.id FROM reachable
                )
                SQL, ['{'.implode(',', $subjectGroupIds).'}'])
            ->with('authzSubjectConditions.user', fn (Relation $user) => $user->effectiveOn($date))
            ->with('authzSubjectConditions.department', fn (Relation $department) => $department->effectiveOn($date))
            ->with('authzSubjectConditions.companyPost', fn (Relation $companyPost) => $companyPost->effectiveOn($date))
            ->get()
            ->keyBy('id');
    }

    /**
     * カレント組織の所属の、読み込み済みの所属役職(候補を引くときに d で絞り済み)の役職。同じ役職を重ねない。カレント組織が無ければ空。
     *
     * @return Collection<int, CompanyPost>
     */
    private function companyPostsOf(?DepartmentMembership $currentMembership): Collection
    {
        if ($currentMembership === null) {
            return new Collection;
        }

        $companyPosts = [];

        foreach ($currentMembership->departmentMembershipPosts as $membershipPost) {
            $companyPosts[$membershipPost->company_post_id] = $membershipPost->companyPost
                ?? throw new LogicException("所属役職 {$membershipPost->id} の役職を読み込んでいない");
        }

        return new Collection(array_values($companyPosts));
    }

    /**
     * まとめて読んだ組織から、カレント組織の分の id を引く。カレント組織が無ければ空。
     *
     * @param  array<int, Collection<int, Department>>  $reached
     * @return array<int, int>
     */
    private function reachedDepartmentIds(array $reached, ?DepartmentMembership $currentMembership): array
    {
        if ($currentMembership === null) {
            return [];
        }

        return $reached[$currentMembership->department_id]->map(fn (Department $department) => $department->id)->all();
    }
}
