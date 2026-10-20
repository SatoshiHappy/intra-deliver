<?php

namespace Tests\Feature\Queries\Authz;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Queries\Authz\Api\SelectionSubjects;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\Support\TransactionalTestCase;

/**
 * 用途 selection の対象者の組み方と条件の公開の口(docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節: 対象者の id → 組み方と条件
 * (種類・相手の id・比較・相手の d の名前)。用途 selection でない・無い id は結果に入れない。条件 0 件の対象者は conditions が空)。
 * 対象者は書き込みの口(CreateSelectionSubjectGroup)で作り、相手は初期データ(u0014 は 2025-10-01 に退職)を使う。
 */
final class SelectionSubjectsTest extends TransactionalTestCase
{
    public function test_returns_the_combination_and_the_conditions_with_the_names_on_the_date(): void
    {
        $user = User::query()->where('code', 'u0001')->sole()->id;
        $department = Department::query()->where('code', 'general-affairs')->sole()->id;
        $post = CompanyPost::query()->where('code', 'manager')->sole()->id;
        $role = Role::query()->where('code', 'general_user')->sole()->id;
        $groupId = $this->createSelection([
            new SelectionCondition(SelectionConditionKind::User, $user),
            new SelectionCondition(SelectionConditionKind::Department, $department),
            new SelectionCondition(SelectionConditionKind::CompanyPost, $post),
            new SelectionCondition(SelectionConditionKind::Role, $role),
        ]);

        $this->assertSame([$groupId => ['combination' => SubjectCombination::Any, 'conditions' => [
            ['kind' => SelectionConditionKind::User, 'id' => $user, 'comparison' => ConditionOperator::Equal, 'name' => '佐々木 陽太'],
            ['kind' => SelectionConditionKind::Department, 'id' => $department, 'comparison' => ConditionOperator::Equal, 'name' => '総務部'],
            ['kind' => SelectionConditionKind::CompanyPost, 'id' => $post, 'comparison' => ConditionOperator::Equal, 'name' => '部長'],
            ['kind' => SelectionConditionKind::Role, 'id' => $role, 'comparison' => ConditionOperator::Equal, 'name' => '一般利用者'],
        ]]], $this->subjects()->conditionsOf([$groupId], $this->day('2026-01-01'), 'ja'));
        $this->assertSame(
            ['Yota Sasaki', 'General Affairs Dept.', 'General Manager', 'General user'],
            array_map(fn (array $condition) => $condition['name'], $this->subjects()->conditionsOf([$groupId], $this->day('2026-01-01'), 'en')[$groupId]['conditions']),
        );
    }

    public function test_the_name_is_null_without_a_term_on_the_date_or_a_name_in_the_locale(): void
    {
        $retired = User::query()->where('code', 'u0014')->sole()->id;
        $groupId = $this->createSelection([new SelectionCondition(SelectionConditionKind::User, $retired), new SelectionCondition(SelectionConditionKind::Role, Role::query()->where('code', 'general_user')->sole()->id)]);

        $this->assertSame(['木村 綾', '一般利用者'], $this->names($groupId, '2025-09-30', 'ja'));
        $this->assertSame([null, '一般利用者'], $this->names($groupId, '2025-10-01', 'ja'));
        $this->assertSame([null, null], $this->names($groupId, '2025-09-30', 'fr'));
    }

    public function test_leaves_out_ids_not_for_selection_or_missing_and_keeps_the_given_order_without_duplicates(): void
    {
        $first = $this->createSelection([new SelectionCondition(SelectionConditionKind::User, User::query()->where('code', 'u0001')->sole()->id)]);
        $second = $this->createSelection([new SelectionCondition(SelectionConditionKind::User, User::query()->where('code', 'u0002')->sole()->id)]);
        $policy = AuthzSubjectGroup::factory()->create(['purpose' => SubjectGroupPurpose::Policy]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $policy->id]);
        $missing = (int) AuthzSubjectGroup::query()->max('id') + 1;

        $this->assertSame([$second, $first], array_keys($this->subjects()->conditionsOf([$second, $policy->id, $missing, $first, $second], $this->day('2026-01-01'), 'ja')));
        $this->assertSame([], $this->subjects()->conditionsOf([], $this->day('2026-01-01'), 'ja'));
    }

    public function test_a_subject_group_without_conditions_has_empty_conditions(): void
    {
        $empty = AuthzSubjectGroup::factory()->create(['purpose' => SubjectGroupPurpose::Selection, 'combination' => SubjectCombination::All]);

        $this->assertSame([$empty->id => ['combination' => SubjectCombination::All, 'conditions' => []]], $this->subjects()->conditionsOf([$empty->id], $this->day('2026-01-01'), 'ja'));
    }

    public function test_a_condition_that_the_writing_port_does_not_make_is_a_logic_error(): void
    {
        $group = AuthzSubjectGroup::factory()->create(['purpose' => SubjectGroupPurpose::Selection]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, 'user_id' => null, 'meta_subject' => MetaSubject::Authenticated]);

        $this->expectException(LogicException::class);

        $this->subjects()->conditionsOf([$group->id], $this->day('2026-01-01'), 'ja');
    }

    private function subjects(): SelectionSubjects
    {
        return app(SelectionSubjects::class);
    }

    /**
     * @param  list<SelectionCondition>  $conditions
     */
    private function createSelection(array $conditions): int
    {
        return app(CreateSelectionSubjectGroup::class)->execute($conditions, ['ja' => '指定']);
    }

    /**
     * @return list<string|null>
     */
    private function names(int $groupId, string $date, string $localeCode): array
    {
        return array_map(fn (array $condition) => $condition['name'], $this->subjects()->conditionsOf([$groupId], $this->day($date), $localeCode)[$groupId]['conditions']);
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }
}
