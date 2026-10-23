<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Queries\Master\MembershipQuery;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザーそれぞれのカレント組織の候補(docs の 設計/デモユーザー切替/今の利用者.md 5.5 の 1): ユーザーごとの候補と並びが 1 人の口
 * ({@see MembershipQuery::currentDepartmentCandidatesOn()})と同じで、ユーザーを取り違えない。
 */
final class CurrentDepartmentCandidatesAmongTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    public function test_reads_the_candidates_of_each_user_in_the_order_of_one_user(): void
    {
        $defaultSet = DepartmentSet::factory()->create(['is_default' => true]);
        $otherSet = DepartmentSet::factory()->create();
        $concurrent = $this->user();
        $second = $this->join($concurrent, $this->department($defaultSet), isMain: true, sortKey: 2);
        $first = $this->join($concurrent, $this->department($defaultSet), sortKey: 1);
        $single = $this->user();
        $only = $this->join($single, $this->department($defaultSet), isMain: true);
        $outside = $this->user();
        $this->join($outside, $this->department($otherSet), isMain: true);
        $retired = $this->user([['2020-01-01', '2024-04-01']]);
        $this->join($retired, $this->department($defaultSet), isMain: true);
        $userIds = [$single->id, $concurrent->id, $outside->id, $retired->id];

        $candidates = app(MembershipQuery::class)->currentDepartmentCandidatesAmongOn($userIds, self::day(self::DATE));

        $this->assertSame($userIds, array_keys($candidates));
        $this->assertSame([$only->id], $candidates[$single->id]->modelKeys());
        $this->assertSame([$first->id, $second->id], $candidates[$concurrent->id]->modelKeys());
        $this->assertSame([], $candidates[$outside->id]->modelKeys());
        $this->assertSame([], $candidates[$retired->id]->modelKeys());

        foreach ($userIds as $userId) {
            $alone = app(MembershipQuery::class)->currentDepartmentCandidatesOn($userId, self::day(self::DATE));
            $this->assertSame($alone->modelKeys(), $candidates[$userId]->modelKeys());
        }
    }

    public function test_loads_the_department_and_the_posts_of_each_candidate(): void
    {
        $defaultSet = DepartmentSet::factory()->create(['is_default' => true]);
        $users = [$this->user(), $this->user()];
        $posts = [$this->companyPost($defaultSet, 40), $this->companyPost($defaultSet, 50)];

        foreach ($users as $index => $user) {
            $this->assignPost($this->join($user, $this->department($defaultSet), isMain: true), $posts[$index]);
        }

        $candidates = app(MembershipQuery::class)
            ->currentDepartmentCandidatesAmongOn([$users[0]->id, $users[1]->id], self::day(self::DATE));

        foreach ($users as $index => $user) {
            $membership = $candidates[$user->id]->sole();
            $this->assertInstanceOf(DepartmentMembership::class, $membership);
            $this->assertTrue($membership->relationLoaded('department'));
            $membershipPosts = $membership->departmentMembershipPosts;
            $this->assertSame([$posts[$index]->id], $membershipPosts->pluck('company_post_id')->all());
            $this->assertTrue($membership->departmentMembershipPosts->sole()->relationLoaded('companyPost'));
        }
    }

    public function test_no_user_reads_nothing_without_a_query(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $candidates = app(MembershipQuery::class)->currentDepartmentCandidatesAmongOn([], self::day(self::DATE));
        DB::disableQueryLog();

        $this->assertSame([], $candidates);
        $this->assertSame([], DB::getQueryLog());
    }
}
