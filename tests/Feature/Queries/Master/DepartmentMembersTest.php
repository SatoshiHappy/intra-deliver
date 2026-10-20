<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Master\Api\DepartmentMembers;
use App\Queries\Master\MembershipQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 組織(と役職)に当たるユーザーの公開の口(docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節: d に成り立つ所属(主所属・兼務)、役職ありなら
 * d に成り立つ所属役職、d に有効なユーザー。昇順)。所属の引き方は共通マスタの 9 節({@see MembershipQuery::membershipsOfDepartmentOn()})と同じ。
 * 初期データ(兼務の u0009、2025-04-01 の異動の u0013・昇格の u0012、2025-10-01 に退職の u0014、第二営業部の新設)で確かめる。
 * 逆向きの departmentIdsOn(同じ 3 節の表)= ユーザーが d に所属する組織(主所属・兼務。下位の組織と期間の外の所属を返さない)。
 */
final class DepartmentMembersTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    public function test_returns_the_users_with_a_main_or_concurrent_membership_in_ascending_order_without_duplicates(): void
    {
        $this->assertSame(['u0009'], $this->memberCodes(['human-resources'], null, '2026-01-01'));
        $this->assertSame(['u0009'], $this->memberCodes(['general-affairs', 'human-resources'], null, '2026-01-01'));
        $this->assertSame(['u0011', 'u0012', 'u0013'], $this->memberCodes(['development'], null, '2025-03-31'));
        $this->assertSame(['u0011', 'u0012'], $this->memberCodes(['development'], null, '2025-04-01'));
        $this->assertSame(['u0010', 'u0014'], $this->memberCodes(['accounting'], null, '2025-09-30'));
        $this->assertSame(['u0010'], $this->memberCodes(['accounting'], null, '2025-10-01'));
        $this->assertSame([], $this->memberCodes(['sales-2'], null, '2025-03-31'));
        $this->assertSame([], $this->members()->userIdsOn([], null, self::day('2026-01-01')));
    }

    public function test_with_a_post_returns_only_the_users_whose_membership_has_that_post_on_the_date(): void
    {
        $this->assertSame(['u0012'], $this->memberCodes(['development'], 'chief', '2025-03-31'));
        $this->assertSame([], $this->memberCodes(['development'], 'section-chief', '2025-03-31'));
        $this->assertSame(['u0012'], $this->memberCodes(['development'], 'section-chief', '2025-04-01'));
        $this->assertSame(['u0009', 'u0010'], $this->memberCodes(['general-affairs', 'human-resources', 'accounting'], 'manager', '2026-01-01'));
    }

    public function test_holds_the_same_members_as_the_reading_of_the_common_master(): void
    {
        foreach (['2025-03-31', '2025-10-01', '2026-01-01'] as $date) {
            foreach (Department::query()->pluck('id')->map(fn (mixed $id) => (int) $id) as $departmentId) {
                $expected = array_values(array_unique(app(MembershipQuery::class)->membershipsOfDepartmentOn($departmentId, self::day($date))->map(fn (DepartmentMembership $membership) => $membership->user_id)->all()));
                sort($expected);

                $this->assertSame($expected, $this->members()->userIdsOn([$departmentId], null, self::day($date)), "{$departmentId} {$date}");
            }
        }
    }

    public function test_leaves_out_a_user_or_a_post_not_effective_on_the_date(): void
    {
        $set = DepartmentSet::factory()->create();
        $department = $this->department($set);
        $leaving = $this->user([['2020-01-01', '2026-01-01']]);
        $staying = $this->user();
        $post = $this->companyPost($set, 30, [['2020-01-01', '2026-01-01']]);
        $this->join($leaving, $department, isMain: true);
        $this->assignPost($this->join($staying, $department, isMain: true), $post);

        $this->assertSame([$leaving->id, $staying->id], $this->members()->userIdsOn([$department->id], null, self::day('2025-12-31')));
        $this->assertSame([$staying->id], $this->members()->userIdsOn([$department->id], null, self::day('2026-01-01')));
        $this->assertSame([$staying->id], $this->members()->userIdsOn([$department->id], $post->id, self::day('2025-12-31')));
        $this->assertSame([], $this->members()->userIdsOn([$department->id], $post->id, self::day('2026-01-01')));
    }

    public function test_department_ids_on_returns_the_main_and_concurrent_departments_without_the_lower_ones(): void
    {
        $concurrent = $this->departmentIds(['general-affairs', 'human-resources']);

        $this->assertSame($concurrent, $this->departmentsOf('u0009', '2026-01-01'));
        $this->assertSame($this->departmentIds(['development']), $this->departmentsOf('u0013', '2025-03-31'));
        $this->assertSame([], $this->departmentsOf('u0014', '2025-10-01'));

        $set = DepartmentSet::factory()->create();
        [$parent, $child, $other] = [$this->department($set), $this->department($set), $this->department($set)];
        $this->include($parent, $child);
        $user = $this->user();
        $this->join($user, $parent, isMain: true);
        $this->join($user, $other, ['2020-01-01', '2026-01-01']);

        $before = $this->members()->departmentIdsOn($user->id, self::day('2025-12-31'));
        $after = $this->members()->departmentIdsOn($user->id, self::day('2026-01-01'));

        $this->assertSame([$parent->id, $other->id], $before);
        $this->assertSame([$parent->id], $after);
        $this->assertSame([], $this->members()->departmentIdsOn(999_999_999, self::day('2026-01-01')));
    }

    /**
     * @return list<int>
     */
    private function departmentsOf(string $userCode, string $date): array
    {
        return $this->members()->departmentIdsOn(User::query()->where('code', $userCode)->sole()->id, self::day($date));
    }

    /**
     * @param  list<string>  $codes
     * @return list<int> 組織の id(昇順)
     */
    private function departmentIds(array $codes): array
    {
        $ids = Department::query()->whereIn('code', $codes)->orderBy('id')->pluck('id')->all();

        return array_values(array_map('intval', $ids));
    }

    private function members(): DepartmentMembers
    {
        return app(DepartmentMembers::class);
    }

    /**
     * @param  list<string>  $departmentCodes
     * @return list<string>
     */
    private function memberCodes(array $departmentCodes, ?string $postCode, string $date): array
    {
        $departmentIds = array_values(array_map('intval', Department::query()->whereIn('code', $departmentCodes)->pluck('id')->all()));
        $postId = $postCode === null ? null : CompanyPost::query()->where('code', $postCode)->sole()->id;
        $codes = User::query()->pluck('code', 'id')->all();

        return array_map(fn (int $id) => $codes[$id], $this->members()->userIdsOn($departmentIds, $postId, self::day($date)));
    }
}
