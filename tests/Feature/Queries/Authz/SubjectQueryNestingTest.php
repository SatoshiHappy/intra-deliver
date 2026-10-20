<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\SubjectQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の対象者の入れ子(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_conditions の「除外と組み方」: 子の対象者の「相手に合う」は
 * 子の「式に合う」。対象者に合うには、(a) たどれる対象者に循環も条件 0 件の対象者も無く、(b) たどれる対象者の子を指さない条件の
 * 少なくとも 1 つの相手に合い、(c) 式に合う。(b) は判定する対象者にだけ掛け、子の「式に合う」には掛けない)。
 *
 * 利用者 = alice・bob(ロール R を持つ)と carol(ロールを持たない)。
 */
final class SubjectQueryNestingTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private User $alice;

    private User $bob;

    private User $carol;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->role = Role::factory()->create();
        $this->alice = $this->user();
        $this->bob = $this->user();
        $this->carol = $this->user();
        $this->grant($this->alice, $this->role);
        $this->grant($this->bob, $this->role);
    }

    public function test_all_with_a_child_of_any_needs_the_child_and_the_other_conditions(): void
    {
        $child = $this->subjectGroup(SubjectCombination::Any, [['user_id' => $this->alice->id], ['user_id' => $this->carol->id]]);
        $group = $this->subjectGroup(SubjectCombination::All, [['child_authz_subject_group_id' => $child->id], ['role_id' => $this->role->id]]);

        $this->assertTrue($this->isMatchedBy($this->alice, $group));
        $this->assertFalse($this->isMatchedBy($this->bob, $group), 'R を持つが子に合わない');
        $this->assertFalse($this->isMatchedBy($this->carol, $group), '子に合うが R を持たない');
    }

    public function test_a_negated_child_excludes_those_who_match_the_child(): void
    {
        $child = $this->subjectGroup(SubjectCombination::Any, [['user_id' => $this->bob->id]]);
        $group = $this->subjectGroup(SubjectCombination::All, [['child_authz_subject_group_id' => $child->id, 'is_negated' => true], ['role_id' => $this->role->id]]);

        $this->assertTrue($this->isMatchedBy($this->alice, $group));
        $this->assertFalse($this->isMatchedBy($this->bob, $group));
    }

    public function test_a_child_reached_along_two_paths_is_not_a_cycle(): void
    {
        $shared = $this->subjectGroup(SubjectCombination::Any, [['user_id' => $this->alice->id]]);
        $left = $this->subjectGroup(SubjectCombination::Any, [['child_authz_subject_group_id' => $shared->id]]);
        $right = $this->subjectGroup(SubjectCombination::Any, [['child_authz_subject_group_id' => $shared->id]]);
        $group = $this->subjectGroup(SubjectCombination::All, [['child_authz_subject_group_id' => $left->id], ['child_authz_subject_group_id' => $right->id]]);

        $this->assertTrue($this->isMatchedBy($this->alice, $group));
        $this->assertFalse($this->isMatchedBy($this->bob, $group));
    }

    public function test_a_subject_group_in_a_cycle_of_two_matches_nobody(): void
    {
        $group = $this->subjectGroup(SubjectCombination::Any, [['user_id' => $this->alice->id]]);
        $child = $this->subjectGroup(SubjectCombination::Any, [['child_authz_subject_group_id' => $group->id]]);
        $this->addConditions($group, [['child_authz_subject_group_id' => $child->id]]);

        $this->assertFalse($this->isMatchedBy($this->alice, $group));
        $this->assertFalse($this->isMatchedBy($this->alice, $child));
    }

    public function test_a_cycle_behind_a_negated_child_matches_nobody(): void
    {
        $first = $this->subjectGroup(SubjectCombination::Any, [['user_id' => $this->bob->id]]);
        $second = $this->subjectGroup(SubjectCombination::Any, [['child_authz_subject_group_id' => $first->id]]);
        $this->addConditions($first, [['child_authz_subject_group_id' => $second->id]]);
        $group = $this->subjectGroup(SubjectCombination::All, [['child_authz_subject_group_id' => $first->id, 'is_negated' => true], ['user_id' => $this->alice->id]]);

        $this->assertFalse($this->isMatchedBy($this->alice, $group));
    }

    public function test_a_negated_child_without_conditions_matches_nobody(): void
    {
        $empty = AuthzSubjectGroup::factory()->create();
        $group = $this->subjectGroup(SubjectCombination::All, [['child_authz_subject_group_id' => $empty->id, 'is_negated' => true], ['user_id' => $this->alice->id]]);

        $this->assertFalse($this->isMatchedBy($this->alice, $group));
    }

    public function test_a_requester_who_matches_only_a_target_inside_a_child_is_a_candidate(): void
    {
        $child = $this->subjectGroup(SubjectCombination::Any, [['user_id' => $this->alice->id]]);
        $group = $this->subjectGroup(SubjectCombination::Any, [['child_authz_subject_group_id' => $child->id]]);

        $this->assertTrue($this->isMatchedBy($this->alice, $group));
        $this->assertFalse($this->isMatchedBy($this->bob, $group));
    }

    /**
     * 子 = 「alice だけを除外」(alice 以外に合う)。外側で子を除外し R と組むと、R を持つ alice だけに合う
     * ((b) を子の「式に合う」に掛けると、bob は子に合わないことになり、外側の除外で反転して合ってしまう)。
     */
    public function test_negating_a_child_that_excludes_one_user_does_not_match_widely(): void
    {
        $child = $this->subjectGroup(SubjectCombination::All, [['user_id' => $this->alice->id, 'is_negated' => true]]);
        $group = $this->subjectGroup(SubjectCombination::All, [['child_authz_subject_group_id' => $child->id, 'is_negated' => true], ['role_id' => $this->role->id]]);

        $this->assertTrue($this->isMatchedBy($this->alice, $group));
        $this->assertFalse($this->isMatchedBy($this->bob, $group));
        $this->assertFalse($this->isMatchedBy($this->carol, $group));
    }

    /**
     * @param  list<array<string, mixed>>  $conditions  条件ごとの列(相手の列 1 つと is_negated)
     */
    private function subjectGroup(SubjectCombination $combination, array $conditions): AuthzSubjectGroup
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => $combination]);
        $this->addConditions($group, $conditions);

        return $group;
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    private function addConditions(AuthzSubjectGroup $group, array $conditions): void
    {
        foreach ($conditions as $condition) {
            AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, 'user_id' => null, ...$condition]);
        }
    }

    private function isMatchedBy(User $user, AuthzSubjectGroup $group): bool
    {
        return app(SubjectQuery::class)->matchesAny(new Requester($user->id, null), [$group->id], self::day(self::DATE));
    }
}
