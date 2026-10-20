<?php

namespace App\Queries\Authz;

use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Master\DepartmentTreeQuery;
use App\Queries\Master\MembershipQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * 認可の対象者に、判定を求める者が d に合うか(docs の 設計/共通マスタ/ロールと認可/対象者.md)。
 *
 * 条件の相手に合うかは判定を求める者と d で決める: ユーザー = その人、組織 = カレント組織の所属がその組織(と比較で決まる上位・下位の組織)にある、
 * 役職 = カレント組織の所属の、d に成り立つ所属役職の役職、ロール = d に持つロール、IPv4 アドレス = 接続元がパターンに合う、認証状態 = d に有効なユーザーの有無、
 * 期間 = d が [開始日, 終了日) の中。判定を求める者のユーザーが d に有効でない・存在しないときは、ユーザーなしとして扱う。
 * 組織と役職(所属から来る事実)はカレント組織の所属 1 つだけから作り、カレント組織が無ければどれも合わない
 * (カレント組織の決まり方 = {@see MembershipQuery::currentMembershipOn()}。docs の 設計/デモユーザー切替/今の利用者.md 5.5)。
 * 条件が指すマスタ(ユーザー・組織・役職)が d に有効でなければ、その条件はどの判定を求める者にも合わない。
 * 子の対象者 = その子の対象者の式に合う。除外と式の組み方・「対象者に合う」は {@see SubjectGroupMatcher}。
 */
class SubjectQuery
{
    /**
     * 組み立てた判定を求める者の d の事実(キー = ユーザー ID・選んだ組織 ID・接続元の IPv4 アドレス・基準日の値)。
     * インスタンスの中だけで覚える。singleton・scoped に束縛しない(要求をまたいで覚えない = docs の 設計/メニュー/表示.md 3.1 の 8)。
     *
     * @var array<string, Subject>
     */
    private array $subjects = [];

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

        return $this->subjects[$key] ??= $this->buildSubjectOn($requester, $date);
    }

    /**
     * 判定を求める者の d の事実を組み立てる({@see self::subjectOn()} の中身)。
     */
    private function buildSubjectOn(Requester $requester, DateTimeInterface $date): Subject
    {
        $userId = $requester->userId;

        if ($userId === null || ! User::query()->whereKey($userId)->effectiveOn($date)->exists()) {
            return new Subject(
                userId: null,
                ipv4Address: $requester->ipv4Address,
                date: $date,
                departmentIds: [],
                upperDepartmentIds: [],
                lowerDepartmentIds: [],
                companyPosts: new Collection,
                roleIds: [],
            );
        }

        $currentMembership = $this->memberships->currentMembershipOn($userId, $requester->selectedDepartmentId, $date);
        $departmentId = $currentMembership?->department_id;

        return new Subject(
            userId: $userId,
            ipv4Address: $requester->ipv4Address,
            date: $date,
            departmentIds: $departmentId === null ? [] : [$departmentId],
            upperDepartmentIds: $departmentId === null ? [] : $this->departmentIdsOf($this->departmentTree->ancestors($departmentId, $date)),
            lowerDepartmentIds: $departmentId === null ? [] : $this->departmentIdsOf($this->departmentTree->descendants($departmentId, $date)),
            companyPosts: $this->companyPostsOn($currentMembership, $date),
            roleIds: $this->roles->rolesOn($userId, $date)->map(fn (Role $role) => $role->id)->all(),
        );
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
     * カレント組織の所属の、d に成り立つ所属役職の役職(所属役職と役職の「成り立つ」は DepartmentMembershipPost の holdsOn。同じ役職を重ねない)。
     * カレント組織が無ければ空。
     *
     * @return Collection<int, CompanyPost>
     */
    private function companyPostsOn(?DepartmentMembership $currentMembership, DateTimeInterface $date): Collection
    {
        if ($currentMembership === null) {
            return new Collection;
        }

        return CompanyPost::query()
            ->whereHas('departmentMembershipPosts', fn (Builder $membershipPosts) => $membershipPosts
                ->holdsOn($date)
                ->where('department_membership_id', $currentMembership->id))
            ->get();
    }

    /**
     * @param  Collection<int, Department>  $departments
     * @return array<int, int>
     */
    private function departmentIdsOf(Collection $departments): array
    {
        return $departments->map(fn (Department $department) => $department->id)->all();
    }
}
