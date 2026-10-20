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
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\SubjectQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の対象者の条件(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_conditions と authz_subject_groups)。
 *
 * 組織の木 = 頂点 R > A > B > C(既定の組織セット)。利用者は B に主所属し(カレント組織 = B)、役職は係長(ランク 40)。
 * 役職のランク = 部長 30・係長 40・主任 50・課長代理 40(係長と同じ)。
 * 判定を求める者はユーザーと選んだ組織(接続元なし)。外部キーで指さない種類(IPv4 アドレス・認証状態・期間)とユーザーのいない判定は SubjectQueryRequesterTest。
 * カレント組織の決まり方と、所属から来る事実をカレント組織の所属 1 つから作ることは SubjectQueryCurrentDepartmentTest。
 */
final class SubjectQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private DepartmentSet $set;

    private User $user;

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, CompanyPost> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->set = DepartmentSet::factory()->create(['is_default' => true]);
        $this->departments['R'] = $this->department($this->set, isRoot: true);

        foreach (['A' => 'R', 'B' => 'A', 'C' => 'B'] as $name => $parent) {
            $this->departments[$name] = $this->department($this->set);
            $this->include($this->departments[$parent], $this->departments[$name]);
        }

        foreach (['部長' => 30, '係長' => 40, '主任' => 50, '課長代理' => 40] as $name => $rank) {
            $this->posts[$name] = $this->companyPost($this->set, $rank);
        }

        $this->user = $this->user();
        $this->assignPost($this->join($this->user, $this->departments['B'], isMain: true), $this->posts['係長']);
    }

    /**
     * @return array<string, array{ConditionOperator, string, bool}>
     */
    public static function departmentComparisons(): array
    {
        return [
            'eq 所属の組織' => [ConditionOperator::Equal, 'B', true],
            'eq 上位の組織' => [ConditionOperator::Equal, 'A', false],
            'lt 上位の組織(利用者は下位組織にいる)' => [ConditionOperator::LessThan, 'A', true],
            'lt 頂点(2 段上)' => [ConditionOperator::LessThan, 'R', true],
            'lt 所属の組織' => [ConditionOperator::LessThan, 'B', false],
            'lt 下位の組織' => [ConditionOperator::LessThan, 'C', false],
            'le 所属の組織' => [ConditionOperator::LessThanOrEqual, 'B', true],
            'le 上位の組織' => [ConditionOperator::LessThanOrEqual, 'A', true],
            'le 下位の組織' => [ConditionOperator::LessThanOrEqual, 'C', false],
            'ge 所属の組織' => [ConditionOperator::GreaterThanOrEqual, 'B', true],
            'ge 下位の組織' => [ConditionOperator::GreaterThanOrEqual, 'C', true],
            'ge 上位の組織' => [ConditionOperator::GreaterThanOrEqual, 'A', false],
            'gt 下位の組織(利用者は上位組織にいる)' => [ConditionOperator::GreaterThan, 'C', true],
            'gt 所属の組織' => [ConditionOperator::GreaterThan, 'B', false],
            'gt 上位の組織' => [ConditionOperator::GreaterThan, 'A', false],
        ];
    }

    #[DataProvider('departmentComparisons')]
    public function test_compares_the_department_of_the_user_with_the_department_of_the_condition(ConditionOperator $operator, string $department, bool $expected): void
    {
        $group = $this->subjectGroup(SubjectCombination::Any, [['department_id' => $this->departments[$department]->id, 'operator' => $operator]]);

        $this->assertSame($expected, $this->isMatched($this->user, $group));
    }

    /**
     * @return array<string, array{ConditionOperator, string, bool}>
     */
    public static function companyPostComparisons(): array
    {
        return [
            'eq 指定の役職' => [ConditionOperator::Equal, '係長', true],
            'eq ランクが同じ別の役職' => [ConditionOperator::Equal, '課長代理', false],
            'lt 上位の役職(利用者はランクが下位)' => [ConditionOperator::LessThan, '部長', true],
            'lt 指定の役職' => [ConditionOperator::LessThan, '係長', false],
            'le ランクが同じ別の役職' => [ConditionOperator::LessThanOrEqual, '課長代理', true],
            'le 上位の役職' => [ConditionOperator::LessThanOrEqual, '部長', true],
            'le 下位の役職' => [ConditionOperator::LessThanOrEqual, '主任', false],
            'ge ランクが同じ別の役職' => [ConditionOperator::GreaterThanOrEqual, '課長代理', true],
            'ge 下位の役職' => [ConditionOperator::GreaterThanOrEqual, '主任', true],
            'ge 上位の役職' => [ConditionOperator::GreaterThanOrEqual, '部長', false],
            'gt 下位の役職(利用者はランクが上位)' => [ConditionOperator::GreaterThan, '主任', true],
            'gt 指定の役職' => [ConditionOperator::GreaterThan, '係長', false],
        ];
    }

    #[DataProvider('companyPostComparisons')]
    public function test_compares_the_rank_of_the_post_of_the_user_with_the_post_of_the_condition(ConditionOperator $operator, string $post, bool $expected): void
    {
        $group = $this->subjectGroup(SubjectCombination::Any, [['company_post_id' => $this->posts[$post]->id, 'operator' => $operator]]);

        $this->assertSame($expected, $this->isMatched($this->user, $group));
    }

    public function test_compares_posts_only_within_the_same_department_set(): void
    {
        $otherPost = $this->companyPost(DepartmentSet::factory()->create(), 90);

        foreach ([ConditionOperator::GreaterThanOrEqual, ConditionOperator::GreaterThan] as $operator) {
            $group = $this->subjectGroup(SubjectCombination::Any, [['company_post_id' => $otherPost->id, 'operator' => $operator]]);
            $this->assertFalse($this->isMatched($this->user, $group), $operator->value);
        }
    }

    public function test_a_condition_on_a_post_that_is_not_effective_matches_nobody(): void
    {
        $retired = $this->companyPost($this->set, 50, [['2020-01-01', self::DATE]]);
        $group = $this->subjectGroup(SubjectCombination::Any, [['company_post_id' => $retired->id, 'operator' => ConditionOperator::GreaterThanOrEqual]]);

        $this->assertTrue($this->isMatched($this->user, $group, '2024-03-31'));
        $this->assertFalse($this->isMatched($this->user, $group));
    }

    public function test_a_post_counts_only_while_its_membership_holds(): void
    {
        $user = $this->user();
        $this->assignPost($this->join($user, $this->departments['C'], ['2020-01-01', self::DATE], isMain: true), $this->posts['部長']);
        $group = $this->subjectGroup(SubjectCombination::Any, [['company_post_id' => $this->posts['部長']->id]]);

        $this->assertTrue($this->isMatched($user, $group, '2024-03-31'));
        $this->assertFalse($this->isMatched($user, $group));
    }

    public function test_a_condition_on_a_department_that_is_not_effective_matches_nobody(): void
    {
        $this->departments['B']->departmentTerms()->update(['is_deleted' => true]);

        foreach (ConditionOperator::cases() as $operator) {
            $group = $this->subjectGroup(SubjectCombination::Any, [['department_id' => $this->departments['B']->id, 'operator' => $operator]]);
            $this->assertFalse($this->isMatched($this->user, $group), $operator->value);
        }
    }

    public function test_a_user_condition_matches_only_that_user_while_the_user_is_effective(): void
    {
        $user = $this->user([['2020-01-01', self::DATE]]);
        $group = $this->subjectGroup(SubjectCombination::Any, [['user_id' => $user->id]]);

        $this->assertTrue($this->isMatched($user, $group, '2024-03-31'));
        $this->assertFalse($this->isMatched($this->user, $group, '2024-03-31'));
        $this->assertFalse($this->isMatched($user, $group));
    }

    public function test_a_role_condition_matches_the_holders_of_the_role_and_of_its_parent_roles(): void
    {
        $parent = Role::factory()->create();
        $child = Role::factory()->create();
        RoleInclusion::factory()->create(['parent_role_id' => $parent->id, 'child_role_id' => $child->id]);
        $holderOfParent = $this->user();
        $holderOfChild = $this->user();
        $this->grant($holderOfParent, $parent);
        $this->grant($holderOfChild, $child);

        $onChild = $this->subjectGroup(SubjectCombination::Any, [['role_id' => $child->id]]);
        $onParent = $this->subjectGroup(SubjectCombination::Any, [['role_id' => $parent->id]]);

        $this->assertTrue($this->isMatched($holderOfParent, $onChild));
        $this->assertTrue($this->isMatched($holderOfChild, $onChild));
        $this->assertTrue($this->isMatched($holderOfParent, $onParent));
        $this->assertFalse($this->isMatched($holderOfChild, $onParent));
    }

    public function test_all_needs_every_condition_and_combines_them_within_the_current_department(): void
    {
        $user = $this->user();
        $this->join($user, $this->departments['A'], isMain: true);
        $this->assignPost($this->join($user, $this->departments['C']), $this->posts['主任']);
        $departmentAndPostOfTheConcurrentMembership = $this->subjectGroup(SubjectCombination::All, [['department_id' => $this->departments['C']->id], ['company_post_id' => $this->posts['主任']->id]]);
        $departmentAndPostInDifferentMemberships = $this->subjectGroup(SubjectCombination::All, [['department_id' => $this->departments['A']->id], ['company_post_id' => $this->posts['主任']->id]]);
        $departmentAndPostNotHeld = [['department_id' => $this->departments['A']->id], ['company_post_id' => $this->posts['部長']->id]];

        $this->assertFalse($this->isMatched($user, $departmentAndPostOfTheConcurrentMembership));
        $this->assertTrue($this->isMatched($user, $departmentAndPostOfTheConcurrentMembership, selectedDepartmentId: $this->departments['C']->id));
        $this->assertFalse($this->isMatched($user, $departmentAndPostInDifferentMemberships));
        $this->assertFalse($this->isMatched($user, $departmentAndPostInDifferentMemberships, selectedDepartmentId: $this->departments['C']->id));
        $this->assertFalse($this->isMatched($user, $this->subjectGroup(SubjectCombination::All, $departmentAndPostNotHeld)));
        $this->assertTrue($this->isMatched($user, $this->subjectGroup(SubjectCombination::Any, $departmentAndPostNotHeld)));
    }

    public function test_a_subject_group_without_conditions_matches_nobody(): void
    {
        foreach (SubjectCombination::cases() as $combination) {
            $this->assertFalse($this->isMatched($this->user, $this->subjectGroup($combination, [])), $combination->value);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $conditions  条件ごとの列(相手の列 1 つと operator)
     */
    private function subjectGroup(SubjectCombination $combination, array $conditions): AuthzSubjectGroup
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => $combination]);

        foreach ($conditions as $condition) {
            AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, 'user_id' => null, ...$condition]);
        }

        return $group;
    }

    private function isMatched(User $user, AuthzSubjectGroup $group, string $date = self::DATE, ?int $selectedDepartmentId = null): bool
    {
        return app(SubjectQuery::class)->matchesAny(new Requester($user->id, null, $selectedDepartmentId), [$group->id], self::day($date));
    }
}
