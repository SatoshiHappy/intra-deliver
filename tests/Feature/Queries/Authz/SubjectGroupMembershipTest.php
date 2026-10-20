<?php

namespace Tests\Feature\Queries\Authz;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Queries\Master\MembershipQuery;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の対象者の本人の判定と展開(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1、設計/共通マスタ/ロールと認可/対象者.md の「展開」「除外と組み方」、
 * 設計/ワークフロー/案件の操作/同時実行と検査.md 7 節の「展開」)。初期データ(兼務 = u0009 が総務部・人事部、異動 = u0013・u0016 が 2025-04-01、
 * 退職 = u0014 が 2025-10-01、入社予定 = u0015 が 2026-11-01、ワークフロー管理者 = u0002 と u0009(2026-03-31 まで))で確かめる。
 */
final class SubjectGroupMembershipTest extends TransactionalTestCase
{
    private const TODAY = '2026-01-01';

    public function test_expands_a_user_condition(): void
    {
        $group = $this->selection([[SelectionConditionKind::User, 'u0001']]);

        $this->assertSame($this->userIds(['u0001']), $this->members($group));
    }

    public function test_expands_a_department_condition_to_both_departments_of_a_user_with_a_concurrent_post(): void
    {
        $generalAffairs = $this->selection([[SelectionConditionKind::Department, 'general-affairs']]);
        $humanResources = $this->selection([[SelectionConditionKind::Department, 'human-resources']]);

        $this->assertSame($this->userIds(['u0009']), $this->members($generalAffairs));
        $this->assertSame($this->userIds(['u0009']), $this->members($humanResources));
    }

    public function test_expands_a_company_post_condition_through_the_memberships_on_the_date(): void
    {
        $managers = $this->selection([[SelectionConditionKind::CompanyPost, 'manager']]);

        $this->assertSame($this->userIds(['u0003', 'u0009', 'u0010', 'u0011', 'u0017']), $this->members($managers, '2024-06-01'));
        $this->assertSame($this->userIds(['u0003', 'u0009', 'u0010', 'u0011', 'u0016', 'u0017']), $this->members($managers, self::TODAY));
    }

    public function test_expands_a_role_condition_with_the_roles_held_on_the_date(): void
    {
        $workflowAdmins = $this->selection([[SelectionConditionKind::Role, 'workflow_admin']]);

        $this->assertSame($this->userIds(['u0002', 'u0009']), $this->members($workflowAdmins, '2026-03-31'));
        $this->assertSame($this->userIds(['u0002']), $this->members($workflowAdmins, '2026-04-01'));
    }

    public function test_a_transfer_changes_the_department_the_user_is_expanded_in_by_the_date(): void
    {
        $sales1 = $this->selection([[SelectionConditionKind::Department, 'sales-1']]);
        $development = $this->selection([[SelectionConditionKind::Department, 'development']]);

        $this->assertSame($this->userIds(['u0003', 'u0016']), $this->members($sales1, '2025-03-31'));
        $this->assertSame($this->userIds(['u0003', 'u0013']), $this->members($sales1, '2025-04-01'));
        $this->assertContains($this->userId('u0013'), $this->members($development, '2025-03-31'));
        $this->assertNotContains($this->userId('u0013'), $this->members($development, '2025-04-01'));
    }

    public function test_a_retired_user_is_not_expanded_from_the_retirement_date_and_a_future_user_not_before_joining(): void
    {
        $accounting = $this->selection([[SelectionConditionKind::Department, 'accounting']]);
        $users = $this->selection([[SelectionConditionKind::User, 'u0014'], [SelectionConditionKind::User, 'u0015']]);

        $this->assertSame($this->userIds(['u0010', 'u0014']), $this->members($accounting, '2025-09-30'));
        $this->assertSame($this->userIds(['u0010']), $this->members($accounting, '2025-10-01'));
        $this->assertSame([], $this->members($users, self::TODAY));
        $this->assertSame($this->userIds(['u0015']), $this->members($users, '2026-11-01'));
    }

    public function test_expands_any_of_the_conditions(): void
    {
        $group = $this->selection([[SelectionConditionKind::User, 'u0001'], [SelectionConditionKind::Department, 'accounting']]);

        $this->assertSame($this->userIds(['u0001', 'u0010']), $this->members($group));
    }

    public function test_a_negation_applies_per_candidate_department(): void
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::All]);
        $this->condition($group, ['user_id' => $this->userId('u0009')]);
        $this->condition($group, ['department_id' => $this->departmentId('general-affairs'), 'is_negated' => true]);

        $this->assertSame($this->userIds(['u0009']), $this->members($group->id));
        $this->assertSame([], $this->membership()->matchingIds(new Requester($this->userId('u0009'), null), [$group->id], $this->day(self::TODAY)));
    }

    public function test_an_ipv4_condition_matches_no_expansion(): void
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        $this->condition($group, ['ipv4_pattern' => '*.*.*.*']);

        $this->assertSame([], $this->members($group->id));
    }

    public function test_an_authenticated_condition_expands_to_every_user_effective_on_the_date(): void
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        $this->condition($group, ['meta_subject' => MetaSubject::Authenticated]);

        $this->assertSame(
            User::query()->effectiveOn($this->day(self::TODAY))->orderBy('id')->pluck('id')->all(),
            $this->members($group->id),
        );
        $this->assertNotContains($this->userId('u0014'), $this->members($group->id));
    }

    public function test_a_missing_subject_group_or_one_without_conditions_or_in_a_cycle_expands_to_nobody(): void
    {
        $empty = AuthzSubjectGroup::factory()->create();
        [$first, $second] = $this->cycle();

        $this->assertSame([], $this->members((int) AuthzSubjectGroup::query()->max('id') + 1));
        $this->assertSame([], $this->members($empty->id));
        $this->assertSame([], $this->members($first));
        $this->assertSame([], $this->members($second));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dates(): array
    {
        return ['異動の前' => ['2025-03-31'], '今' => [self::TODAY]];
    }

    /**
     * 本人の判定で合う人は展開に必ず入る: 初期データの d に有効な全ユーザーと、カレント組織の候補(と選んでいない = 主所属)の組で、
     * 初期データのすべての対象者とテストで作った対象者を確かめる(d に有効でないユーザーは判定でユーザーなしになり、展開に入らない)。
     */
    #[DataProvider('dates')]
    public function test_every_user_who_matches_with_a_candidate_department_is_in_the_expansion(string $day): void
    {
        $date = $this->day($day);
        $created = [
            $this->selection([[SelectionConditionKind::Department, 'human-resources']]),
            $this->selection([[SelectionConditionKind::CompanyPost, 'manager'], [SelectionConditionKind::User, 'u0013']]),
            $this->selection([[SelectionConditionKind::Role, 'general_user']]),
        ];
        $groupIds = array_values(array_unique([...AuthzSubjectGroup::query()->orderBy('id')->pluck('id')->all(), ...$created]));
        $expansions = array_combine($groupIds, array_map(fn (int $groupId) => $this->members($groupId, $day), $groupIds));
        $checked = 0;

        foreach (User::query()->effectiveOn($date)->orderBy('id')->pluck('id')->all() as $userId) {
            $candidates = app(MembershipQuery::class)->currentDepartmentCandidatesOn($userId, $date)
                ->map(fn (DepartmentMembership $membership) => $membership->department_id)
                ->all();

            foreach ([null, ...$candidates] as $departmentId) {
                foreach ($this->membership()->matchingIds(new Requester($userId, null, $departmentId), $groupIds, $date) as $groupId) {
                    $this->assertContains($userId, $expansions[$groupId], "対象者 {$groupId} に合うユーザー {$userId}(選んだ組織 {$departmentId})が展開に無い");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(count($created), $checked);
    }

    public function test_matching_ids_keeps_the_given_order_without_duplicates_and_leaves_out_missing_or_invalid_subject_groups(): void
    {
        $accounting = $this->selection([[SelectionConditionKind::Department, 'accounting']]);
        $user = $this->selection([[SelectionConditionKind::User, 'u0010']]);
        $other = $this->selection([[SelectionConditionKind::User, 'u0001']]);
        $empty = AuthzSubjectGroup::factory()->create()->id;
        [$cycle] = $this->cycle();
        $missing = (int) AuthzSubjectGroup::query()->max('id') + 1;

        $matching = $this->membership()->matchingIds(
            new Requester($this->userId('u0010'), null),
            [$user, $missing, $other, $accounting, $empty, $user, $cycle],
            $this->day(self::TODAY),
        );

        $this->assertSame([$user, $accounting], $matching);
        $this->assertSame([], $this->membership()->matchingIds(new Requester($this->userId('u0010'), null), [], $this->day(self::TODAY)));
    }

    public function test_matching_ids_treats_a_user_not_effective_on_the_date_as_no_user(): void
    {
        $user = $this->selection([[SelectionConditionKind::User, 'u0014']]);
        $guests = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        $this->condition($guests, ['meta_subject' => MetaSubject::Anonymous]);
        $requester = new Requester($this->userId('u0014'), null);

        $this->assertSame([$user], $this->membership()->matchingIds($requester, [$user, $guests->id], $this->day('2025-09-30')));
        $this->assertSame([$guests->id], $this->membership()->matchingIds($requester, [$user, $guests->id], $this->day('2025-10-01')));
    }

    public function test_effective_user_id_is_the_user_only_while_effective_on_the_date(): void
    {
        $retired = $this->userId('u0014');
        $missing = (int) User::query()->max('id') + 1;

        $this->assertSame($retired, $this->membership()->effectiveUserId(new Requester($retired, null), $this->day('2025-09-30')));
        $this->assertNull($this->membership()->effectiveUserId(new Requester($retired, null), $this->day('2025-10-01')));
        $this->assertNull($this->membership()->effectiveUserId(new Requester($this->userId('u0015'), null), $this->day(self::TODAY)));
        $this->assertNull($this->membership()->effectiveUserId(new Requester($missing, null), $this->day(self::TODAY)));
        $this->assertNull($this->membership()->effectiveUserId(new Requester(null, null), $this->day(self::TODAY)));
    }

    private function membership(): SubjectGroupMembership
    {
        return app(SubjectGroupMembership::class);
    }

    /**
     * @return list<int>
     */
    private function members(int $subjectGroupId, string $date = self::TODAY): array
    {
        return $this->membership()->memberUserIdsOn($subjectGroupId, $this->day($date));
    }

    /**
     * 初期データのコードで指す条件の、用途 selection の対象者。
     *
     * @param  list<array{SelectionConditionKind, string}>  $conditions
     */
    private function selection(array $conditions): int
    {
        return app(CreateSelectionSubjectGroup::class)->execute(
            array_map(fn (array $condition) => new SelectionCondition($condition[0], $this->idOf($condition[0], $condition[1])), $conditions),
            ['ja' => '展開のテスト'],
        );
    }

    private function idOf(SelectionConditionKind $kind, string $code): int
    {
        $query = match ($kind) {
            SelectionConditionKind::User => User::query(),
            SelectionConditionKind::Department => Department::query(),
            SelectionConditionKind::CompanyPost => CompanyPost::query(),
            SelectionConditionKind::Role => Role::query(),
        };

        return $query->where('code', $code)->sole()->getKey();
    }

    /**
     * @param  array<string, mixed>  $values  条件の列(相手を指す列。factory の既定のユーザーは外す)
     */
    private function condition(AuthzSubjectGroup $group, array $values): void
    {
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, 'user_id' => null, ...$values]);
    }

    /**
     * 互いを子に持つ 2 つの対象者(どちらも u0001 の条件も持つ)。
     *
     * @return array{int, int}
     */
    private function cycle(): array
    {
        $first = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        $second = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);

        foreach ([[$first, $second], [$second, $first]] as [$parent, $child]) {
            $this->condition($parent, ['user_id' => $this->userId('u0001')]);
            $this->condition($parent, ['child_authz_subject_group_id' => $child->id]);
        }

        return [$first->id, $second->id];
    }

    /**
     * @param  list<string>  $codes
     * @return list<int> 昇順
     */
    private function userIds(array $codes): array
    {
        $ids = array_map(fn (string $code) => $this->userId($code), $codes);
        sort($ids);

        return $ids;
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }
}
