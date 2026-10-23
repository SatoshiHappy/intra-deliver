<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Queries\Master\MembershipQuery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 対象者の展開(docs の 設計/共通マスタ/ロールと認可/対象者.md の「展開」)が、d に有効な全ユーザー × カレント組織の候補(無ければ選んでいない者)で
 * 本人の判定({@see SubjectGroupMembership::matchingIds()})をした結果の和と一致することを、別のインスタンスの判定と照らして確かめる。
 *
 * 組織の木(既定の組織セット)= R > A > B > C、A > D。役職のランク = 部長 30・係長 40・主任 50。ロール = P(サブロール Q)・Q。
 * 利用者 = u1 と u2(B。係長と主任)、u3(C から 2025-01-01 に D へ異動。P)、u4(D。部長。Q)、u5(B が主所属で D に兼務。D の所属は係長)、
 * u6(既定でない組織セットにだけ所属 = 候補なし)、u7(C。2025-01-01 に退職)、u8 と u9(B。d に無効な役職 = ランク 20 と 60)。
 */
final class SubjectGroupExpansionCrossCheckTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const BEFORE = '2024-04-01';

    private const AFTER = '2025-04-01';

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, CompanyPost> */
    private array $posts = [];

    /** @var array<string, Role> */
    private array $roles = [];

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $set = DepartmentSet::factory()->create(['is_default' => true]);
        $this->departments['R'] = $this->department($set, isRoot: true);

        foreach (['A' => 'R', 'B' => 'A', 'C' => 'B', 'D' => 'A'] as $name => $parent) {
            $this->departments[$name] = $this->department($set);
            $this->include($this->departments[$parent], $this->departments[$name]);
        }

        foreach (['部長' => 30, '係長' => 40, '主任' => 50] as $name => $rank) {
            $this->posts[$name] = $this->companyPost($set, $rank);
        }

        foreach (['旧役員' => 20, '旧嘱託' => 60] as $name => $rank) {
            $this->posts[$name] = $this->companyPost($set, $rank, [['2020-01-01', '2024-01-01']]);
        }

        $this->roles = ['P' => Role::factory()->create(), 'Q' => Role::factory()->create()];
        RoleInclusion::factory()->create([
            'parent_role_id' => $this->roles['P']->id,
            'child_role_id' => $this->roles['Q']->id,
        ]);
        $this->createUsers();
    }

    /**
     * 照らす対象者: ユーザー・組織の 5 つの比較(B を指す)・役職の比較・ロールとサブロール・認証状態・期間・除外・子の対象者を混ぜる。
     */
    #[TestWith([self::BEFORE])]
    #[TestWith([self::AFTER])]
    public function test_the_expansion_is_the_union_of_the_single_judgments_over_users_and_candidates(string $day): void
    {
        $groups = $this->subjectGroups();
        $expansion = app(SubjectGroupMembership::class);

        $reference = $this->singleJudgments(array_values($groups), $day);

        foreach ($groups as $name => $groupId) {
            $members = $expansion->memberUserIdsOn($groupId, self::day($day));
            $this->assertSame($reference[$groupId], $members, "対象者 {$name}");
        }

        $this->assertContains($this->users['u5']->id, $reference[$groups['認証済みで D でない']]);
        $this->assertNotContains($this->users['u4']->id, $reference[$groups['認証済みで D でない']]);
        $this->assertNotSame([], $reference[$groups['ロール Q']]);
    }

    /**
     * 所属役職の役職が d に無効なら、条件の役職(d に有効)と比べない: ランクが上位(小さい)の役職でも ge に、下位(大きい)の役職でも le に合わない。
     */
    #[TestWith([ConditionOperator::GreaterThanOrEqual, 'u8'])]
    #[TestWith([ConditionOperator::LessThanOrEqual, 'u9'])]
    public function test_an_ineffective_post_is_not_compared(ConditionOperator $operator, string $user): void
    {
        $group = $this->group(SubjectCombination::Any, [$this->postCondition($operator)]);
        $date = self::day(self::BEFORE);
        $userId = $this->users[$user]->id;
        $membership = app(SubjectGroupMembership::class);

        $this->assertSame([], $membership->matchingIds(new Requester($userId, null), [$group], $date));
        $this->assertNotContains($userId, $membership->memberUserIdsOn($group, $date));
        $this->assertContains($this->users['u1']->id, $membership->memberUserIdsOn($group, $date));
    }

    private function createUsers(): void
    {
        $until = ['2020-01-01', '2025-01-01'];
        $this->users['u1'] = $this->member('B', '係長');
        $this->users['u2'] = $this->member('B', '主任');
        $this->users['u3'] = $this->member('C', null, $until);
        $this->join($this->users['u3'], $this->departments['D'], ['2025-01-01', null], isMain: true);
        $this->grant($this->users['u3'], $this->roles['P']);
        $this->users['u4'] = $this->member('D', '部長');
        $this->grant($this->users['u4'], $this->roles['Q']);
        $this->users['u5'] = $this->member('B', null);
        $this->assignPost($this->join($this->users['u5'], $this->departments['D']), $this->posts['係長']);
        $this->users['u6'] = $this->user();
        $this->join($this->users['u6'], $this->department(DepartmentSet::factory()->create()), isMain: true);
        $this->users['u7'] = $this->user([$until]);
        $this->join($this->users['u7'], $this->departments['C'], isMain: true);
        $this->users['u8'] = $this->member('B', '旧役員');
        $this->users['u9'] = $this->member('B', '旧嘱託');
    }

    /**
     * 組織に主所属するユーザー(役職を渡せばその所属役職も)。
     *
     * @param  array{0: string, 1: string|null}  $term  主所属の期間
     */
    private function member(string $department, ?string $post, array $term = ['2020-01-01', null]): User
    {
        $user = $this->user();
        $membership = $this->join($user, $this->departments[$department], $term, isMain: true);

        if ($post !== null) {
            $this->assignPost($membership, $this->posts[$post]);
        }

        return $user;
    }

    /**
     * @return array<string, int>
     */
    private function subjectGroups(): array
    {
        $b = $this->departments['B']->id;
        $groups = ['ユーザー u1' => $this->anyOf(['user_id' => $this->users['u1']->id])];

        foreach (ConditionOperator::cases() as $operator) {
            $groups["組織 B {$operator->value}"] = $this->anyOf(['department_id' => $b, 'operator' => $operator]);
        }

        return [
            ...$groups,
            '役職 係長 ge' => $this->anyOf($this->postCondition(ConditionOperator::GreaterThanOrEqual)),
            '役職 係長 le' => $this->anyOf($this->postCondition(ConditionOperator::LessThanOrEqual)),
            'ロール P' => $this->anyOf(['role_id' => $this->roles['P']->id]),
            'ロール Q' => $this->anyOf(['role_id' => $this->roles['Q']->id]),
            'ゲスト' => $this->anyOf(['meta_subject' => MetaSubject::Anonymous]),
            '期間' => $this->anyOf(['term_start_date' => '2024-01-01', 'term_end_date' => '2025-01-01']),
            '認証済みで D でない' => $this->group(SubjectCombination::All, [
                ['meta_subject' => MetaSubject::Authenticated],
                ['department_id' => $this->departments['D']->id, 'is_negated' => true],
            ]),
            '子の対象者か P' => $this->group(SubjectCombination::Any, [
                ['child_authz_subject_group_id' => $groups['ユーザー u1']],
                ['role_id' => $this->roles['P']->id],
            ]),
            'A 以下で係長以下' => $this->group(SubjectCombination::All, [
                ['department_id' => $this->departments['A']->id, 'operator' => ConditionOperator::GreaterThanOrEqual],
                $this->postCondition(ConditionOperator::LessThanOrEqual),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postCondition(ConditionOperator $operator): array
    {
        return ['company_post_id' => $this->posts['係長']->id, 'operator' => $operator];
    }

    /**
     * 照らす側: d に有効な全ユーザーそれぞれの候補(無ければ選んでいない者 1 人)で、別のインスタンスの本人の判定をした結果の和(対象者 → ユーザーの id の昇順)。
     *
     * @param  list<int>  $groupIds
     * @return array<int, list<int>>
     */
    private function singleJudgments(array $groupIds, string $day): array
    {
        $date = self::day($day);
        $judge = app(SubjectGroupMembership::class);
        $members = array_fill_keys($groupIds, []);

        foreach (User::query()->effectiveOn($date)->orderBy('id')->pluck('id')->all() as $userId) {
            $candidates = app(MembershipQuery::class)->currentDepartmentCandidatesOn($userId, $date)
                ->map(fn (DepartmentMembership $membership) => $membership->department_id)
                ->all();

            foreach ($candidates === [] ? [null] : $candidates as $departmentId) {
                $requester = new Requester($userId, null, $departmentId);

                foreach ($judge->matchingIds($requester, $groupIds, $date) as $groupId) {
                    $members[$groupId][$userId] = $userId;
                }
            }
        }

        return array_map(fn (array $userIds) => array_values($userIds), $members);
    }

    /**
     * @param  list<array<string, mixed>>  $conditions  条件ごとの列(相手の列 1 つと operator・is_negated)
     */
    private function group(SubjectCombination $combination, array $conditions): int
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => $combination]);

        foreach ($conditions as $condition) {
            AuthzSubjectCondition::factory()
                ->create(['authz_subject_group_id' => $group->id, 'user_id' => null, ...$condition]);
        }

        return $group->id;
    }

    /**
     * 条件 1 つの対象者。
     *
     * @param  array<string, mixed>  $condition
     */
    private function anyOf(array $condition): int
    {
        return $this->group(SubjectCombination::Any, [$condition]);
    }
}
