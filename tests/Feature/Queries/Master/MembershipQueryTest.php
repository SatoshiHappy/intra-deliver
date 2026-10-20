<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Models\Master\DepartmentSet;
use App\Queries\Master\MembershipQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザーの d に成り立つ所属と所属役職(docs の 設計/共通マスタ/共通の規則.md 5.4: 関係が成り立つ = 行が d に有効で、指すマスタもすべて d に有効。
 * 連動する子(所属役職)は、親の所属が成り立ち、子の指す役職も d に有効なときに成り立つ。組織とユーザー.md 8 節・9 節)と、
 * カレント組織の候補と決まり方(docs の 設計/デモユーザー切替/今の利用者.md 5.5: 候補 = d に成り立つ所属のうち既定の組織セットのもの。
 * 選んだ組織が候補にあればその所属、無ければ候補のうち主所属の最初、それも無ければ無い)。
 */
final class MembershipQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private DepartmentSet $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->set = DepartmentSet::factory()->create();
    }

    public function test_reads_concurrent_memberships_with_the_main_flag(): void
    {
        $user = $this->user();
        $main = $this->join($user, $this->department($this->set), isMain: true, sortKey: 2);
        $concurrent = $this->join($user, $this->department($this->set), sortKey: 1);

        $memberships = app(MembershipQuery::class)->membershipsOn($user->id, self::day('2024-04-01'));

        $this->assertSame([$concurrent->id, $main->id], $memberships->modelKeys());
        $this->assertSame([false, true], $memberships->map(fn (DepartmentMembership $membership) => $membership->is_main)->all());
        $this->assertSame($main->department_id, $memberships->get(1)?->department?->id);
    }

    public function test_reads_the_memberships_of_a_department_on_the_date_without_those_of_lower_departments(): void
    {
        $department = $this->department($this->set);
        $lower = $this->department($this->set);
        $this->include($department, $lower);
        $concurrent = $this->join($this->user(), $department, sortKey: 2);
        $main = $this->join($this->user(), $department, isMain: true, sortKey: 1);
        $this->join($this->user([['2020-01-01', '2024-04-01']]), $department, isMain: true);
        $this->join($this->user(), $department, ['2024-04-02', null], isMain: true);
        $this->join($this->user(), $lower, isMain: true);

        $memberships = app(MembershipQuery::class)->membershipsOfDepartmentOn($department->id, self::day('2024-04-01'));

        $this->assertSame([$main->id, $concurrent->id], $memberships->modelKeys());
        $this->assertSame($main->user_id, $memberships->first()?->user?->id);
    }

    public function test_a_membership_holds_from_its_start_date_until_the_day_before_its_end_date(): void
    {
        $user = $this->user();
        $this->join($user, $this->department($this->set), ['2024-04-01', '2025-04-01']);

        $this->assertSame([false, true, true, false], $this->holdsOnDates($user->id, ['2024-03-31', '2024-04-01', '2025-03-31', '2025-04-01']));
    }

    public function test_a_deleted_membership_does_not_hold(): void
    {
        $user = $this->user();
        $this->join($user, $this->department($this->set), ['2020-01-01', null, true]);

        $this->assertSame([false], $this->holdsOnDates($user->id, ['2024-04-01']));
    }

    public function test_no_membership_holds_in_a_gap_between_membership_rows(): void
    {
        $user = $this->user();
        $department = $this->department($this->set);
        $this->join($user, $department, ['2020-01-01', '2024-04-01']);
        $this->join($user, $department, ['2024-10-01', null]);

        $this->assertSame([true, false, false, true], $this->holdsOnDates($user->id, ['2024-03-31', '2024-04-01', '2024-09-30', '2024-10-01']));
    }

    public function test_a_membership_does_not_hold_when_its_department_is_not_effective(): void
    {
        $user = $this->user();
        $this->join($user, $this->department($this->set, [['2020-01-01', '2024-04-01'], ['2024-04-01', '2025-04-01', true], ['2025-04-01', null]]));
        $this->join($user, $this->department($this->set, [['2024-07-01', null]]));

        $this->assertSame([true, false, false, true], $this->holdsOnDates($user->id, ['2024-03-31', '2024-04-01', '2024-06-30', '2025-04-01']));
    }

    public function test_a_membership_does_not_hold_when_its_user_is_not_effective(): void
    {
        $user = $this->user([['2020-01-01', '2024-04-01'], ['2024-04-01', null, true]]);
        $this->join($user, $this->department($this->set));

        $this->assertSame([true, false], $this->holdsOnDates($user->id, ['2024-03-31', '2024-04-01']));
    }

    public function test_reads_only_the_posts_that_are_effective_on_the_date(): void
    {
        $user = $this->user();
        $membership = $this->join($user, $this->department($this->set));
        $manager = $this->assignPost($membership, $this->companyPost($this->set, 30));
        $this->assignPost($membership, $this->companyPost($this->set, 40, [['2020-01-01', '2024-04-01']]));
        $this->assignPost($membership, $this->companyPost($this->set, 50, [['2020-01-01', null, true]]));

        $memberships = app(MembershipQuery::class)->membershipsOn($user->id, self::day('2024-04-01'));

        $posts = $memberships->sole()->departmentMembershipPosts;
        $this->assertSame([$manager->id], $posts->modelKeys());
        $this->assertSame(30, $posts->map(fn (DepartmentMembershipPost $post) => $post->companyPost?->rank)->sole());
    }

    public function test_the_candidates_are_the_memberships_in_the_default_department_set_in_order(): void
    {
        $defaultSet = DepartmentSet::factory()->create(['is_default' => true]);
        $user = $this->user();
        $second = $this->join($user, $this->department($defaultSet), isMain: true, sortKey: 2);
        $first = $this->join($user, $this->department($defaultSet), sortKey: 1);
        $this->join($user, $this->department($this->set), isMain: true, sortKey: 0);
        $this->join($user, $this->department($defaultSet), ['2020-01-01', '2024-04-01'], sortKey: 0);

        $candidates = app(MembershipQuery::class)->currentDepartmentCandidatesOn($user->id, self::day('2024-04-01'));

        $this->assertSame([$first->id, $second->id], $candidates->modelKeys());
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function selectionsOfTheCurrentDepartment(): array
    {
        return [
            '選んでいない → 主所属' => [null, 'main'],
            '兼務の組織を選ぶ' => ['concurrent', 'concurrent'],
            '既定でない組織セットの組織を選ぶ → 主所属' => ['otherSet', 'main'],
            '成り立たない所属の組織を選ぶ → 主所属' => ['ended', 'main'],
            '所属していない組織を選ぶ → 主所属' => ['notJoined', 'main'],
        ];
    }

    #[DataProvider('selectionsOfTheCurrentDepartment')]
    public function test_the_current_membership_is_the_selected_candidate_or_else_the_main_one(?string $selected, string $expected): void
    {
        $defaultSet = DepartmentSet::factory()->create(['is_default' => true]);
        $user = $this->user();
        $memberships = [
            'concurrent' => $this->join($user, $this->department($defaultSet), sortKey: 1),
            'main' => $this->join($user, $this->department($defaultSet), isMain: true, sortKey: 2),
            'otherSet' => $this->join($user, $this->department($this->set), isMain: true),
            'ended' => $this->join($user, $this->department($defaultSet), ['2020-01-01', '2024-04-01']),
        ];
        $departmentIds = [...array_map(fn (DepartmentMembership $membership) => $membership->department_id, $memberships), 'notJoined' => $this->department($defaultSet)->id];

        $current = app(MembershipQuery::class)->currentMembershipOn($user->id, $selected === null ? null : $departmentIds[$selected], self::day('2024-04-01'));

        $this->assertSame($memberships[$expected]->id, $current?->id);
    }

    public function test_there_is_no_current_membership_without_a_main_one_unless_one_is_selected(): void
    {
        $defaultSet = DepartmentSet::factory()->create(['is_default' => true]);
        $user = $this->user();
        $concurrent = $this->join($user, $this->department($defaultSet));
        $this->join($user, $this->department($defaultSet), ['2020-01-01', '2024-04-01'], isMain: true);
        $query = app(MembershipQuery::class);

        $this->assertNull($query->currentMembershipOn($user->id, null, self::day('2024-04-01')));
        $this->assertSame($concurrent->id, $query->currentMembershipOn($user->id, $concurrent->department_id, self::day('2024-04-01'))?->id);
    }

    public function test_the_current_membership_has_its_department_and_its_posts_on_the_date(): void
    {
        $defaultSet = DepartmentSet::factory()->create(['is_default' => true]);
        $user = $this->user();
        $membership = $this->join($user, $this->department($defaultSet), isMain: true);
        $manager = $this->assignPost($membership, $this->companyPost($defaultSet, 30));
        $this->assignPost($membership, $this->companyPost($defaultSet, 40, [['2020-01-01', '2024-04-01']]));

        $current = app(MembershipQuery::class)->currentMembershipOn($user->id, null, self::day('2024-04-01'));

        $this->assertNotNull($current);
        $this->assertSame($membership->department_id, $current->department?->id);
        $this->assertSame([$manager->id], $current->departmentMembershipPosts->modelKeys());
        $this->assertSame(30, $current->departmentMembershipPosts->sole()->companyPost?->rank);
    }

    /**
     * 日ごとに、成り立つ所属があるか。
     *
     * @param  list<string>  $dates
     * @return list<bool>
     */
    private function holdsOnDates(int $userId, array $dates): array
    {
        return array_map(fn (string $date) => app(MembershipQuery::class)->membershipsOn($userId, self::day($date))->isNotEmpty(), $dates);
    }
}
