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
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Authz\SubjectQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 渡したユーザーのうち対象者に合う者をまとめて求める口({@see SubjectQuery::matchingUserIdsAmong()}): ユーザーごとに違う候補・ロール・役職を
 * 取り違えず、候補ごとの和を取り、d に有効でない・存在しないユーザーは(ゲストの条件の対象者でも)入れない。
 *
 * 組織 = 頂点 R の下に B と D(既定の組織セット)。役職のランク = 部長 30・係長 40。ロール = P(サブロール Q)・Q。
 * 利用者 = a(B。係長。P)、b(D。部長。Q)、c(B が主所属で D に兼務)、retired(B。d の前に退職)。
 */
final class SubjectQueryMatchingUserIdsTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

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

        foreach (['B', 'D'] as $name) {
            $this->departments[$name] = $this->department($set);
            $this->include($this->departments['R'], $this->departments[$name]);
        }

        $this->posts = ['部長' => $this->companyPost($set, 30), '係長' => $this->companyPost($set, 40)];
        $this->roles = ['P' => Role::factory()->create(), 'Q' => Role::factory()->create()];
        RoleInclusion::factory()->create([
            'parent_role_id' => $this->roles['P']->id,
            'child_role_id' => $this->roles['Q']->id,
        ]);

        $this->users['a'] = $this->user();
        $this->assignPost($this->join($this->users['a'], $this->departments['B'], isMain: true), $this->posts['係長']);
        $this->grant($this->users['a'], $this->roles['P']);
        $this->users['b'] = $this->user();
        $this->assignPost($this->join($this->users['b'], $this->departments['D'], isMain: true), $this->posts['部長']);
        $this->grant($this->users['b'], $this->roles['Q']);
        $this->users['c'] = $this->user();
        $this->join($this->users['c'], $this->departments['B'], isMain: true);
        $this->join($this->users['c'], $this->departments['D']);
        $this->users['retired'] = $this->user([['2020-01-01', '2024-04-01']]);
        $this->join($this->users['retired'], $this->departments['B'], ['2020-01-01', '2024-04-01'], isMain: true);
    }

    public function test_reads_the_facts_of_each_user_without_mixing_them_up(): void
    {
        $matchingOf = fn (array $condition) => $this->matching($this->anyOf($condition), ['c', 'b', 'a']);

        $this->assertSame($this->idsOf(['a']), $matchingOf(['role_id' => $this->roles['P']->id]));
        $this->assertSame($this->idsOf(['a', 'b']), $matchingOf(['role_id' => $this->roles['Q']->id]));
        $this->assertSame($this->idsOf(['b']), $matchingOf($this->postCondition('部長', ConditionOperator::Equal)));
        $this->assertSame($this->idsOf(['a']), $matchingOf($this->postCondition('係長', ConditionOperator::Equal)));
        $this->assertSame([], $matchingOf($this->postCondition('係長', ConditionOperator::LessThan)));
        $this->assertSame($this->idsOf(['b']), $matchingOf($this->postCondition('係長', ConditionOperator::GreaterThan)));
        $this->assertSame($this->idsOf(['a', 'c']), $matchingOf($this->departmentCondition('B')));
        $this->assertSame($this->idsOf(['b', 'c']), $matchingOf($this->departmentCondition('D')));
    }

    public function test_a_user_matches_when_one_of_the_candidates_matches(): void
    {
        $outsideB = $this->group(SubjectCombination::All, [
            ['meta_subject' => MetaSubject::Authenticated],
            [...$this->departmentCondition('B'), 'is_negated' => true],
        ]);

        $this->assertSame($this->idsOf(['b', 'c']), $this->matching($outsideB, ['a', 'b', 'c']));
    }

    public function test_a_user_not_effective_on_the_date_or_missing_is_left_out_even_for_the_guests(): void
    {
        $missing = (int) User::query()->max('id') + 1;
        $retired = $this->users['retired']->id;
        $guests = $this->anyOf(['meta_subject' => MetaSubject::Anonymous]);
        $authenticated = $this->anyOf(['meta_subject' => MetaSubject::Authenticated]);
        $subjects = app(SubjectQuery::class);
        $date = self::day(self::DATE);

        $this->assertSame([], $subjects->matchingUserIdsAmong($guests, [$retired, $missing], $date));
        $this->assertSame(
            $this->idsOf(['a']),
            $subjects->matchingUserIdsAmong($authenticated, [$missing, $retired, $this->users['a']->id], $date),
        );
    }

    public function test_no_user_or_a_missing_subject_group_matches_nobody(): void
    {
        $authenticated = $this->anyOf(['meta_subject' => MetaSubject::Authenticated]);
        $missing = (int) AuthzSubjectGroup::query()->max('id') + 1;

        $this->assertSame([], $this->matching($authenticated, []));
        $this->assertSame([], $this->matching($missing, ['a', 'b', 'c']));
    }

    /**
     * 渡した順のままのユーザーで求める。
     *
     * @param  list<string>  $users
     * @return list<int>
     */
    private function matching(int $subjectGroupId, array $users): array
    {
        $userIds = array_map(fn (string $name) => $this->users[$name]->id, $users);

        return app(SubjectQuery::class)->matchingUserIdsAmong($subjectGroupId, $userIds, self::day(self::DATE));
    }

    /**
     * @return array<string, mixed>
     */
    private function postCondition(string $name, ConditionOperator $operator): array
    {
        return ['company_post_id' => $this->posts[$name]->id, 'operator' => $operator];
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentCondition(string $name): array
    {
        return ['department_id' => $this->departments[$name]->id, 'operator' => ConditionOperator::Equal];
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function anyOf(array $condition): int
    {
        return $this->group(SubjectCombination::Any, [$condition]);
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
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
     * @param  list<string>  $names
     * @return list<int> 昇順
     */
    private function idsOf(array $names): array
    {
        $ids = array_map(fn (string $name) => $this->users[$name]->id, $names);
        sort($ids);

        return $ids;
    }
}
