<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Queries\Authz\Api\SubjectGroupMembership;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 対象者の展開({@see SubjectGroupMembership::memberUserIdsOn()})の問い合わせの数が、d に有効なユーザーの数・組織の数で増えないことを確かめる
 * (前後の差を比べ、上限を置く)。展開は全員の事実をまとめて読むので、ユーザー・候補・組織を足しても本数は同じ。
 *
 * 組織の木(既定の組織セット)= R > A > B。対象者 = 組織 A 以下(A とその下位)・サブロール・役職 係長以上のどれか(所属・役職・ロール・上位と下位をすべて読む)。
 */
final class SubjectGroupExpansionCountTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    /** 1 回の展開の本数の上限(実測は 14 本。対象者と条件の相手・判定を求める者の事実を、それぞれ数本でまとめて読む。余裕を持たせる) */
    private const CEILING = 16;

    private const DATE = '2024-04-01';

    private DepartmentSet $set;

    /** @var array<string, Department> */
    private array $departments = [];

    private CompanyPost $post;

    private Role $role;

    private int $subjectGroupId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->set = DepartmentSet::factory()->create(['is_default' => true]);
        $this->departments['R'] = $this->department($this->set, isRoot: true);

        foreach (['A' => 'R', 'B' => 'A'] as $name => $parent) {
            $this->departments[$name] = $this->department($this->set);
            $this->include($this->departments[$parent], $this->departments[$name]);
        }

        $this->post = $this->companyPost($this->set, 40);
        $this->role = Role::factory()->create();
        $subRole = Role::factory()->create();
        RoleInclusion::factory()->create(['parent_role_id' => $this->role->id, 'child_role_id' => $subRole->id]);
        $this->subjectGroupId = $this->subjectGroup([
            ['department_id' => $this->departments['A']->id, 'operator' => ConditionOperator::LessThanOrEqual],
            ['role_id' => $subRole->id],
            ['company_post_id' => $this->post->id, 'operator' => ConditionOperator::GreaterThanOrEqual],
        ]);
        $this->addMembers($this->departments['B'], 2);
    }

    public function test_the_queries_of_an_expansion_do_not_grow_with_the_users_or_the_departments(): void
    {
        [$before, $members] = $this->expand();
        $this->addMembers($this->departments['B'], 3);
        [$sameDepartment, $moreMembers] = $this->expand();
        $newDepartment = $this->department($this->set);
        $this->include($this->departments['B'], $newDepartment);
        $this->addMembers($newDepartment, 2);
        [$otherDepartment, $mostMembers] = $this->expand();

        $this->assertCount(count($members) + 3, $moreMembers);
        $this->assertCount(count($members) + 5, $mostMembers);
        $this->assertSame($before, $sameDepartment);
        $this->assertSame($before, $otherDepartment);
        $this->assertLessThanOrEqual(self::CEILING, $otherDepartment);
    }

    /**
     * 組織に主所属し、役職とロールを持つユーザーを足す(1 人おきに上位の組織 A に兼務もする)。
     */
    private function addMembers(Department $department, int $count): void
    {
        foreach (range(1, $count) as $index) {
            $user = $this->user();
            $this->assignPost($this->join($user, $department, isMain: true), $this->post);
            $this->grant($user, $this->role);

            if ($index % 2 === 0) {
                $this->join($user, $this->departments['A']);
            }
        }
    }

    /**
     * 展開の本数と結果(新しいインスタンスで数える)。
     *
     * @return array{int, list<int>}
     */
    private function expand(): array
    {
        $expansion = app(SubjectGroupMembership::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $members = $expansion->memberUserIdsOn($this->subjectGroupId, self::day(self::DATE));
        DB::disableQueryLog();

        return [count(DB::getQueryLog()), $members];
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    private function subjectGroup(array $conditions): int
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);

        foreach ($conditions as $condition) {
            AuthzSubjectCondition::factory()
                ->create(['authz_subject_group_id' => $group->id, 'user_id' => null, ...$condition]);
        }

        return $group->id;
    }
}
