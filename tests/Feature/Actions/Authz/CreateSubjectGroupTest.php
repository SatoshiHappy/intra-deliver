<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectCategory;
use App\Models\Authz\AuthzSubjectCategoryTranslation;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Authz\Role;
use App\Models\Master\Department;
use App\Models\Master\Locale;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の対象者を、条件・表示名・説明と一緒に作る(docs の 設計/メニュー/認可との結び付け.md 2.4、設計/共通マスタ/ロールと認可/対象者.md の authz_subject_groups・
 * authz_subject_conditions: 条件は 1 件以上、[m-n] は m ≤ n、
 * all では少なくとも 1 つの条件が除外でない、any では除外を置かない。カテゴリの行が無ければ全ロケールの表示名と一緒に並び順 0 で作る。
 * 用途は既定 policy、用途 selection の対象者を子にしない = 対象者.md の「用途 `selection` の規則」)。
 * カテゴリのコードと表示名の文言の組み合わせは tests/Unit/Enums/Authz/SubjectTypeTest.php。
 */
final class CreateSubjectGroupTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

    public function test_creates_a_subject_group_with_conditions_and_display_names(): void
    {
        $role = Role::factory()->create();

        $subjectGroup = $this->create(SubjectCombination::All, [
            new SubjectConditionInput(roleId: $role->id),
            new SubjectConditionInput(ipv4Pattern: '192.168.0.*', isNegated: true),
        ], ['ja' => ['display_name' => '社内以外のロール', 'description' => '説明'], 'en' => ['display_name' => 'Role outside']], 4);

        $this->assertSame([SubjectCombination::All, 4], [$subjectGroup->combination, $subjectGroup->sort_key]);
        $this->assertEqualsCanonicalizing(
            [[$role->id, null, false], [null, '192.168.0.*', true]],
            $subjectGroup->authzSubjectConditions()->get()->map(fn (AuthzSubjectCondition $condition) => [$condition->role_id, $condition->ipv4_pattern, $condition->is_negated])->all(),
        );
        $this->assertEqualsCanonicalizing(
            [['社内以外のロール', '説明'], ['Role outside', null]],
            $subjectGroup->authzSubjectGroupTranslations()->get()->map(fn (AuthzSubjectGroupTranslation $translation) => [$translation->display_name, $translation->description])->all(),
        );
    }

    public function test_creates_the_category_with_display_names_in_every_locale_and_sort_key_zero(): void
    {
        Locale::factory()->create(['code' => 'fr']);

        $this->create(SubjectCombination::All, [new SubjectConditionInput(ipv4Pattern: '10.0.0.*')]);

        $category = AuthzSubjectCategory::query()->where('code', 'im_authz_ipv4')->sole();
        $this->assertSame(0, $category->sort_key);
        $this->assertSame(['en' => 'IPv4 address', 'fr' => 'IPv4 address', 'ja' => 'IPv4 アドレス'], $this->categoryDisplayNames($category));
    }

    public function test_the_category_counts_the_subject_types_of_child_subject_groups_but_not_the_child_conditions(): void
    {
        $seeded = AuthzSubjectCategory::query()->pluck('code')->all();
        $child = $this->create(SubjectCombination::All, [new SubjectConditionInput(ipv4Pattern: '10.0.0.*')]);
        $middle = $this->create(SubjectCombination::Any, [new SubjectConditionInput(childSubjectGroupId: $child->id)]);

        $this->create(SubjectCombination::All, [
            new SubjectConditionInput(roleId: Role::factory()->create()->id),
            new SubjectConditionInput(childSubjectGroupId: $middle->id),
        ]);

        $this->assertSame(['b_m_role:im_authz_ipv4', 'im_authz_ipv4'], AuthzSubjectCategory::query()->whereNotIn('code', $seeded)->orderBy('code')->pluck('code')->all());
    }

    public function test_keeps_an_existing_category_and_its_display_names(): void
    {
        $category = AuthzSubjectCategory::query()->where('code', 'b_m_role')->sole();
        AuthzSubjectCategoryTranslation::query()->where('authz_subject_category_id', $category->id)->update(['display_name' => '作った時点の名前']);
        $categories = AuthzSubjectCategory::query()->count();

        $this->create(SubjectCombination::All, [new SubjectConditionInput(roleId: Role::factory()->create()->id)]);

        $this->assertSame($categories, AuthzSubjectCategory::query()->count());
        $this->assertSame(Locale::query()->orderBy('code')->pluck('code')->mapWithKeys(fn (string $code) => [$code => '作った時点の名前'])->all(), $this->categoryDisplayNames($category));
    }

    public function test_rejects_a_subject_group_without_conditions(): void
    {
        $counts = $this->counts();

        $this->assertRejected('conditions', fn () => $this->create(SubjectCombination::Any, []));
        $this->assertSame($counts, $this->counts());
    }

    public function test_accepts_ipv4_ranges_whose_start_is_not_greater_than_the_end(): void
    {
        $subjectGroup = $this->create(SubjectCombination::Any, [new SubjectConditionInput(ipv4Pattern: '192.168.[0-24].[7-7]')]);

        $this->assertModelExists($subjectGroup);
    }

    public function test_rejects_an_ipv4_range_whose_start_is_greater_than_the_end(): void
    {
        $counts = $this->counts();

        $this->assertRejected('ipv4_pattern', fn () => $this->create(SubjectCombination::Any, [new SubjectConditionInput(ipv4Pattern: '192.168.[0-24].[8-7]')]));
        $this->assertSame($counts, $this->counts());
    }

    public function test_rejects_all_conditions_negated_in_all(): void
    {
        $counts = $this->counts();

        $this->assertRejected('is_negated', fn () => $this->create(SubjectCombination::All, [
            new SubjectConditionInput(metaSubject: MetaSubject::Anonymous, isNegated: true),
            new SubjectConditionInput(ipv4Pattern: '10.0.0.*', isNegated: true),
        ]));
        $this->assertSame($counts, $this->counts());
    }

    public function test_accepts_a_negated_condition_beside_a_condition_that_is_not_negated_in_all(): void
    {
        $subjectGroup = $this->create(SubjectCombination::All, [
            new SubjectConditionInput(metaSubject: MetaSubject::Authenticated),
            new SubjectConditionInput(ipv4Pattern: '10.0.0.*', isNegated: true),
        ]);

        $this->assertModelExists($subjectGroup);
    }

    public function test_rejects_a_negated_condition_in_any(): void
    {
        $counts = $this->counts();

        $this->assertRejected('is_negated', fn () => $this->create(SubjectCombination::Any, [
            new SubjectConditionInput(metaSubject: MetaSubject::Authenticated),
            new SubjectConditionInput(ipv4Pattern: '10.0.0.*', isNegated: true),
        ]));
        $this->assertSame($counts, $this->counts());
    }

    public function test_rejects_a_child_subject_group_without_conditions_because_the_category_is_undecided(): void
    {
        $child = AuthzSubjectGroup::factory()->create();
        $counts = $this->counts();

        $this->assertRejected('child_authz_subject_group_id', fn () => $this->create(SubjectCombination::Any, [new SubjectConditionInput(childSubjectGroupId: $child->id)]));
        $this->assertSame($counts, $this->counts());
    }

    public function test_creates_a_subject_group_for_policy_unless_the_purpose_is_given(): void
    {
        $role = Role::factory()->create();

        $policy = $this->create(SubjectCombination::All, [new SubjectConditionInput(roleId: $role->id)]);
        $selection = app(CreateSubjectGroup::class)->execute(SubjectCombination::Any, [new SubjectConditionInput(roleId: $role->id)], [], purpose: SubjectGroupPurpose::Selection);

        $this->assertSame([SubjectGroupPurpose::Policy, SubjectGroupPurpose::Selection], [$policy->refresh()->purpose, $selection->refresh()->purpose]);
    }

    public function test_rejects_a_child_subject_group_for_selection(): void
    {
        $selection = AuthzSubjectGroup::factory()->create(['purpose' => SubjectGroupPurpose::Selection]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $selection->id]);
        $policyChild = AuthzSubjectGroup::factory()->create();
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $policyChild->id]);
        $counts = $this->counts();

        $this->assertRejected('child_authz_subject_group_id', fn () => $this->create(SubjectCombination::Any, [
            new SubjectConditionInput(childSubjectGroupId: $policyChild->id),
            new SubjectConditionInput(childSubjectGroupId: $selection->id),
        ]));
        $this->assertSame($counts, $this->counts());
        $this->create(SubjectCombination::Any, [new SubjectConditionInput(childSubjectGroupId: $policyChild->id)]);
    }

    public function test_keeps_the_operator_of_a_department_condition(): void
    {
        $department = Department::factory()->create();

        $subjectGroup = $this->create(SubjectCombination::Any, [new SubjectConditionInput(departmentId: $department->id, operator: ConditionOperator::GreaterThanOrEqual)]);

        $this->assertSame(ConditionOperator::GreaterThanOrEqual, $subjectGroup->authzSubjectConditions()->sole()->operator);
    }

    /**
     * @param  list<SubjectConditionInput>  $conditions
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations
     */
    private function create(SubjectCombination $combination, array $conditions, array $translations = [], int $sortKey = 0): AuthzSubjectGroup
    {
        return app(CreateSubjectGroup::class)->execute($combination, $conditions, $translations, $sortKey);
    }

    /**
     * @return array<string, string> ロケールのコード → 表示名(コードの順)
     */
    private function categoryDisplayNames(AuthzSubjectCategory $category): array
    {
        $localeCodes = Locale::query()->pluck('code', 'id');

        return $category->authzSubjectCategoryTranslations()->get()
            ->mapWithKeys(fn (AuthzSubjectCategoryTranslation $translation) => [$localeCodes[$translation->locale_id] => $translation->display_name])
            ->sortKeys()
            ->all();
    }

    /**
     * 対象者・条件・カテゴリの件数。
     *
     * @return list<int>
     */
    private function counts(): array
    {
        return [AuthzSubjectGroup::query()->count(), AuthzSubjectCondition::query()->count(), AuthzSubjectCategory::query()->count()];
    }
}
