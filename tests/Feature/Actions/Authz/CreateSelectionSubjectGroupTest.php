<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\Locale;
use App\Models\Master\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 用途 selection の専用の対象者を作る公開の口(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1、設計/共通マスタ/ロールと認可/対象者.md の
 * 「用途 `selection` の規則」: 組み方 any、条件はユーザー・組織・役職・ロールの eq(除外・入れ子なし)が 1 件以上、相手が存在する、表示名。誤りは
 * ValidationException(キー conditions・conditions.<添字>.id・translations)。結果は作った対象者の id)。
 */
final class CreateSelectionSubjectGroupTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    public function test_creates_an_any_subject_group_for_selection_with_eq_conditions_of_every_kind(): void
    {
        $user = User::factory()->create();
        $department = Department::factory()->create();
        $companyPost = CompanyPost::factory()->create();
        $role = Role::factory()->create();

        $id = $this->create([
            new SelectionCondition(SelectionConditionKind::User, $user->id),
            new SelectionCondition(SelectionConditionKind::Department, $department->id),
            new SelectionCondition(SelectionConditionKind::CompanyPost, $companyPost->id),
            new SelectionCondition(SelectionConditionKind::Role, $role->id),
        ], ['ja' => '承認者', 'en' => 'Approvers']);

        $subjectGroup = AuthzSubjectGroup::query()->findOrFail($id);
        $this->assertSame([SubjectCombination::Any, SubjectGroupPurpose::Selection], [$subjectGroup->combination, $subjectGroup->purpose]);
        $this->assertEqualsCanonicalizing(
            [
                [$user->id, null, null, null],
                [null, $department->id, null, null],
                [null, null, $companyPost->id, null],
                [null, null, null, $role->id],
            ],
            $subjectGroup->authzSubjectConditions()->get()
                ->map(fn (AuthzSubjectCondition $condition) => [$condition->user_id, $condition->department_id, $condition->company_post_id, $condition->role_id])
                ->all(),
        );
        $this->assertSame(
            [[ConditionOperator::Equal, false, null]],
            $subjectGroup->authzSubjectConditions()->get()
                ->map(fn (AuthzSubjectCondition $condition) => [$condition->operator, $condition->is_negated, $condition->child_authz_subject_group_id])
                ->unique(fn (array $values) => serialize($values))
                ->values()
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            [['承認者', null], ['Approvers', null]],
            $subjectGroup->authzSubjectGroupTranslations()->get()
                ->map(fn (AuthzSubjectGroupTranslation $translation) => [$translation->display_name, $translation->description])
                ->all(),
        );
    }

    public function test_rejects_no_conditions(): void
    {
        $counts = $this->counts();

        $this->assertSame(['conditions'], array_keys($this->validationErrors(fn () => $this->create([], ['ja' => '承認者']))));
        $this->assertSame($counts, $this->counts());
    }

    public function test_rejects_missing_targets_of_each_kind_with_their_index_as_422_without_writing(): void
    {
        $role = Role::factory()->create();
        $missing = (int) DB::selectOne('select greatest((select max(id) from users), (select max(id) from departments), (select max(id) from company_posts), (select max(id) from roles)) + 1 as id')->id;
        $counts = $this->counts();

        $errors = $this->validationErrors(fn () => $this->create([
            new SelectionCondition(SelectionConditionKind::User, $missing),
            new SelectionCondition(SelectionConditionKind::Role, $role->id),
            new SelectionCondition(SelectionConditionKind::Department, $missing),
            new SelectionCondition(SelectionConditionKind::CompanyPost, $missing),
            new SelectionCondition(SelectionConditionKind::Role, $missing),
        ], ['ja' => '承認者']));

        $this->assertSame(['conditions.0.id', 'conditions.2.id', 'conditions.3.id', 'conditions.4.id'], array_keys($errors));
        $this->assertSame($counts, $this->counts());
    }

    public function test_rejects_a_target_of_another_kind_with_the_same_id(): void
    {
        $missingUser = (int) User::query()->max('id') + 1;
        Role::factory()->create(['id' => $missingUser]);

        $errors = $this->validationErrors(fn () => $this->create([new SelectionCondition(SelectionConditionKind::User, $missingUser)], ['ja' => '承認者']));

        $this->assertSame(['conditions.0.id'], array_keys($errors));
    }

    public function test_rejects_missing_display_names_an_unknown_locale_or_an_empty_name(): void
    {
        $role = Role::factory()->create();
        $conditions = [new SelectionCondition(SelectionConditionKind::Role, $role->id)];
        $counts = $this->counts();

        foreach ([[], ['xx' => '承認者'], ['ja' => '承認者', 'en' => '']] as $translations) {
            $this->assertSame(['translations'], array_keys($this->validationErrors(fn () => $this->create($conditions, $translations))));
        }

        $this->assertSame($counts, $this->counts());
    }

    public function test_accepts_display_names_in_any_existing_locale(): void
    {
        Locale::factory()->create(['code' => 'fr']);
        $role = Role::factory()->create();

        $id = $this->create([new SelectionCondition(SelectionConditionKind::Role, $role->id)], ['fr' => 'Approbateurs']);

        $this->assertSame(['Approbateurs'], AuthzSubjectGroupTranslation::query()->where('authz_subject_group_id', $id)->pluck('display_name')->all());
    }

    public function test_is_contained_in_the_outer_transaction_and_rolled_back_with_it(): void
    {
        $role = Role::factory()->create();
        $counts = $this->counts();

        try {
            DB::transaction(function () use ($role) {
                $this->create([new SelectionCondition(SelectionConditionKind::Role, $role->id)], ['ja' => '承認者']);

                throw new RuntimeException('外側の失敗');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame($counts, $this->counts());
    }

    /**
     * @param  list<SelectionCondition>  $conditions
     * @param  array<string, string>  $translations
     */
    private function create(array $conditions, array $translations): int
    {
        return app(CreateSelectionSubjectGroup::class)->execute($conditions, $translations);
    }

    /**
     * 対象者・条件・表示名の件数。
     *
     * @return list<int>
     */
    private function counts(): array
    {
        return [AuthzSubjectGroup::query()->count(), AuthzSubjectCondition::query()->count(), AuthzSubjectGroupTranslation::query()->count()];
    }
}
