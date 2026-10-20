<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\Department;
use Carbon\CarbonImmutable;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * 1 条件の対象者を引くか作る(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_groups(1 条件の対象者を引くか作る): その条件だけを持つ対象者(組み方は問わない)があれば
 * id の最も小さいものを返し、無ければ all の対象者を受けた表示名で作る。条件は除外でなく比較 eq。探すのは用途 policy の対象者だけ = 対象者.md の「用途 `selection` の規則」)。
 */
final class FindOrCreateSingleConditionSubjectGroupTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

    public function test_creates_an_all_subject_group_with_the_condition_and_display_name_when_none_has_it(): void
    {
        $role = Role::factory()->create();

        $subjectGroup = $this->findOrCreate(new SubjectConditionInput(roleId: $role->id), ['ja' => ['display_name' => 'ロールの対象者']]);

        $condition = $subjectGroup->authzSubjectConditions()->sole();
        $this->assertSame(SubjectCombination::All, $subjectGroup->combination);
        $this->assertSame([$role->id, ConditionOperator::Equal, false], [$condition->role_id, $condition->operator, $condition->is_negated]);
        $this->assertSame(['ロールの対象者'], $subjectGroup->authzSubjectGroupTranslations()->pluck('display_name')->all());
    }

    public function test_returns_the_smallest_id_among_subject_groups_with_only_that_condition_in_any_combination(): void
    {
        $role = Role::factory()->create();
        $first = $this->subjectGroupWith(SubjectCombination::Any, ['role_id' => $role->id]);
        $this->subjectGroupWith(SubjectCombination::All, ['role_id' => $role->id]);
        $subjectGroups = AuthzSubjectGroup::query()->count();

        $found = $this->findOrCreate(new SubjectConditionInput(roleId: $role->id));

        $this->assertTrue($found->is($first));
        $this->assertSame($subjectGroups, AuthzSubjectGroup::query()->count());
    }

    public function test_finds_a_term_condition_by_its_dates(): void
    {
        $existing = $this->subjectGroupWith(SubjectCombination::All, ['term_start_date' => '2026-01-01', 'term_end_date' => '2027-01-01']);

        $found = $this->findOrCreate(new SubjectConditionInput(termStartDate: CarbonImmutable::parse('2026-01-01'), termEndDate: CarbonImmutable::parse('2027-01-01')));

        $this->assertTrue($found->is($existing));
    }

    public function test_does_not_take_a_subject_group_with_another_condition_beside_it(): void
    {
        $role = Role::factory()->create();
        $withTwo = $this->subjectGroupWith(SubjectCombination::All, ['role_id' => $role->id]);
        AuthzSubjectCondition::factory()->forMetaSubject()->create(['authz_subject_group_id' => $withTwo->id]);

        $found = $this->findOrCreate(new SubjectConditionInput(roleId: $role->id));

        $this->assertFalse($found->is($withTwo));
    }

    public function test_does_not_take_a_subject_group_whose_single_condition_differs(): void
    {
        $role = Role::factory()->create();
        $department = Department::factory()->create();
        $otherRole = $this->subjectGroupWith(SubjectCombination::All, ['role_id' => Role::factory()->create()->id]);
        $negated = $this->subjectGroupWith(SubjectCombination::Any, ['role_id' => $role->id, 'is_negated' => true]);
        $otherMetaSubject = $this->subjectGroupWith(SubjectCombination::All, ['meta_subject' => MetaSubject::Authenticated]);
        $otherOperator = $this->subjectGroupWith(SubjectCombination::All, ['department_id' => $department->id, 'operator' => ConditionOperator::GreaterThan]);
        $subjectGroups = AuthzSubjectGroup::query()->count();

        $created = [
            $this->findOrCreate(new SubjectConditionInput(roleId: $role->id)),
            $this->findOrCreate(new SubjectConditionInput(metaSubject: MetaSubject::Anonymous)),
            $this->findOrCreate(new SubjectConditionInput(departmentId: $department->id)),
        ];

        $existingIds = [$otherRole->id, $negated->id, $otherMetaSubject->id, $otherOperator->id];
        $createdIds = array_map(fn (AuthzSubjectGroup $subjectGroup) => $subjectGroup->id, $created);
        $this->assertSame([], array_intersect($existingIds, $createdIds));
        $this->assertCount(3, array_unique($createdIds));
        $this->assertSame($subjectGroups + 2, AuthzSubjectGroup::query()->count());
    }

    public function test_does_not_take_a_subject_group_for_selection_and_creates_one_for_policy(): void
    {
        $role = Role::factory()->create();
        $selection = $this->subjectGroupWith(SubjectCombination::Any, ['role_id' => $role->id], SubjectGroupPurpose::Selection);
        $subjectGroups = AuthzSubjectGroup::query()->count();

        $found = $this->findOrCreate(new SubjectConditionInput(roleId: $role->id));

        $this->assertFalse($found->is($selection));
        $this->assertSame(SubjectGroupPurpose::Policy, $found->refresh()->purpose);
        $this->assertSame($subjectGroups + 1, AuthzSubjectGroup::query()->count());
        $this->assertTrue($this->findOrCreate(new SubjectConditionInput(roleId: $role->id))->is($found));
    }

    public function test_rejects_a_negated_condition_or_an_operator_other_than_eq(): void
    {
        $department = Department::factory()->create();
        $subjectGroups = AuthzSubjectGroup::query()->count();

        $this->assertRejected('condition', fn () => $this->findOrCreate(new SubjectConditionInput(metaSubject: MetaSubject::Anonymous, isNegated: true)));
        $this->assertRejected('condition', fn () => $this->findOrCreate(new SubjectConditionInput(departmentId: $department->id, operator: ConditionOperator::GreaterThan)));
        $this->assertSame($subjectGroups, AuthzSubjectGroup::query()->count());
    }

    /**
     * @param  array<string, mixed>  $condition  条件の列(相手を指す列。factory の既定のユーザーは外す)
     */
    private function subjectGroupWith(SubjectCombination $combination, array $condition, SubjectGroupPurpose $purpose = SubjectGroupPurpose::Policy): AuthzSubjectGroup
    {
        $subjectGroup = AuthzSubjectGroup::factory()->create(['combination' => $combination, 'purpose' => $purpose]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $subjectGroup->id, 'user_id' => null, ...$condition]);

        return $subjectGroup;
    }

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations
     */
    private function findOrCreate(SubjectConditionInput $condition, array $translations = []): AuthzSubjectGroup
    {
        return app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, $translations);
    }
}
