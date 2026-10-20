<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\SubjectGroupMatcher;
use App\Queries\Authz\SubjectQuery;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;
use UnexpectedValueException;

/**
 * 認可の対象者の条件の除外(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_conditions の「除外と組み方」:
 * 条件の値 = 相手に合うかを除外なら反転したもの。対象者に合うには、(b) 子を指さない条件の少なくとも 1 つの相手に合うことも要る)。
 *
 * 利用者 = alice・bob(ロール R を持つ)と carol(どの条件の相手でもない)。子の対象者を使う組み方は SubjectQueryNestingTest。
 */
final class SubjectQueryNegationTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    public function test_a_negated_user_condition_is_true_without_a_user(): void
    {
        $group = $this->subjectGroup(SubjectCombination::All, [['user_id' => $this->user()->id, 'is_negated' => true], ['meta_subject' => MetaSubject::Anonymous]]);

        $this->assertTrue($this->isMatched(new Requester(null, null), $group));
    }

    public function test_a_negated_condition_on_a_master_that_is_not_effective_is_true(): void
    {
        $alice = $this->user();
        $department = $this->department(DepartmentSet::factory()->create(['is_default' => true]), [['2020-01-01', self::DATE]]);
        $this->join($alice, $department, isMain: true);
        $group = $this->subjectGroup(SubjectCombination::All, [['department_id' => $department->id, 'is_negated' => true], ['user_id' => $alice->id]]);

        $this->assertFalse($this->isMatched(new Requester($alice->id, null), $group, '2024-03-31'));
        $this->assertTrue($this->isMatched(new Requester($alice->id, null), $group));
    }

    public function test_a_negated_ipv4_condition_is_true_without_an_address_of_the_connection(): void
    {
        $alice = $this->user();
        $group = $this->subjectGroup(SubjectCombination::All, [['ipv4_pattern' => '192.168.0.*', 'is_negated' => true], ['user_id' => $alice->id]]);

        $this->assertTrue($this->isMatched(new Requester($alice->id, null), $group));
        $this->assertTrue($this->isMatched(new Requester($alice->id, '10.0.0.1'), $group));
        $this->assertFalse($this->isMatched(new Requester($alice->id, '192.168.0.5'), $group));
    }

    public function test_all_with_a_negated_condition_excludes_its_target(): void
    {
        [$alice, $bob, $role] = $this->aliceAndBobWithARole();
        $group = $this->subjectGroup(SubjectCombination::All, [['role_id' => $role->id], ['user_id' => $bob->id, 'is_negated' => true]]);

        $this->assertTrue($this->isMatchedBy($alice, $group));
        $this->assertFalse($this->isMatchedBy($bob, $group));
    }

    public function test_all_with_only_negated_conditions_matches_nobody(): void
    {
        [$alice, $bob] = $this->aliceAndBobWithARole();
        $group = $this->subjectGroup(SubjectCombination::All, [['user_id' => $alice->id, 'is_negated' => true], ['user_id' => $bob->id, 'is_negated' => true]]);

        foreach ([$alice, $bob, $this->user()] as $user) {
            $this->assertFalse($this->isMatchedBy($user, $group), (string) $user->id);
        }
    }

    public function test_any_with_one_negated_condition_matches_no_more_than_the_other_conditions(): void
    {
        [$alice, $bob] = $this->aliceAndBobWithARole();
        $group = $this->subjectGroup(SubjectCombination::Any, [['user_id' => $alice->id], ['user_id' => $bob->id, 'is_negated' => true]]);

        $this->assertTrue($this->isMatchedBy($alice, $group));
        $this->assertFalse($this->isMatchedBy($bob, $group));
        $this->assertFalse($this->isMatchedBy($this->user(), $group));
    }

    /**
     * 除外が 2 つの any では、除外した一方の相手は、もう一方の除外の値が真なので合う(規則どおりの結果。特別な規則で止めない)。
     */
    public function test_any_with_two_negated_conditions_matches_the_target_of_one_through_the_other(): void
    {
        [$alice, $bob] = $this->aliceAndBobWithARole();
        $group = $this->subjectGroup(SubjectCombination::Any, [['user_id' => $alice->id, 'is_negated' => true], ['user_id' => $bob->id, 'is_negated' => true]]);

        $this->assertTrue($this->isMatchedBy($alice, $group));
        $this->assertTrue($this->isMatchedBy($bob, $group));
        $this->assertFalse($this->isMatchedBy($this->user(), $group));
    }

    /**
     * どの相手の列にも当たらない条件(DB の検査制約が止めるので、読み込んだ形を組み立てて渡す)は、除外でも真にせず例外にする。
     */
    public function test_a_condition_of_a_kind_that_cannot_be_judged_throws_even_when_negated(): void
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        $group->setRelation('authzSubjectConditions', new Collection([
            AuthzSubjectCondition::factory()->negated()->make(['authz_subject_group_id' => $group->id, 'user_id' => null]),
        ]));
        $subject = app(SubjectQuery::class)->subjectOn(new Requester(null, null), self::day(self::DATE));
        $matcher = new SubjectGroupMatcher($subject, new Collection([$group->id => $group]));

        $this->expectException(UnexpectedValueException::class);

        $matcher->matches($group);
    }

    /**
     * ロール R を持つ alice と bob。
     *
     * @return array{User, User, Role}
     */
    private function aliceAndBobWithARole(): array
    {
        $role = Role::factory()->create();
        $alice = $this->user();
        $bob = $this->user();
        $this->grant($alice, $role);
        $this->grant($bob, $role);

        return [$alice, $bob, $role];
    }

    /**
     * @param  list<array<string, mixed>>  $conditions  条件ごとの列(相手の列 1 つと is_negated)
     */
    private function subjectGroup(SubjectCombination $combination, array $conditions): AuthzSubjectGroup
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => $combination]);

        foreach ($conditions as $condition) {
            AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, 'user_id' => null, ...$condition]);
        }

        return $group;
    }

    private function isMatchedBy(User $user, AuthzSubjectGroup $group): bool
    {
        return $this->isMatched(new Requester($user->id, null), $group);
    }

    private function isMatched(Requester $requester, AuthzSubjectGroup $group, string $date = self::DATE): bool
    {
        return app(SubjectQuery::class)->matchesAny($requester, [$group->id], self::day($date));
    }
}
