<?php

namespace Tests\Feature\Actions\Master;

use App\Actions\Master\AssignMembershipPost;
use App\Actions\Master\CreateDepartmentMembership;
use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Support\Period;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 所属を作る・所属役職を付ける(docs の 設計/共通マスタ/組織とユーザー.md 8 節の業務処理で止める規則:
 * 所属が d に有効なら組織・ユーザー・所属役職の役職も d に有効(共通の規則 5.5)、d に有効な所属があるなら d に有効な主所属がある)。
 */
final class CreateDepartmentMembershipTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use BuildsCommonMaster;

    private DepartmentSet $set;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->set = DepartmentSet::factory()->create();
        $this->user = $this->user();
    }

    public function test_creates_a_main_membership_and_a_concurrent_one_in_the_set_of_the_department(): void
    {
        $main = $this->create($this->department($this->set), isMain: true);
        $concurrent = $this->create($this->department($this->set), '2024-04-01', '2025-04-01');

        $this->assertSame([$this->set->id, true, false], [$main->department_set_id, $main->is_main, $main->is_deleted]);
        $this->assertSame([false, '2024-04-01', '2025-04-01'], [$concurrent->is_main, $concurrent->start_date->toDateString(), $concurrent->end_date?->toDateString()]);
    }

    public function test_rejects_a_department_that_is_not_effective_over_the_whole_period(): void
    {
        $department = $this->department($this->set, [['2020-01-01', '2024-06-01'], ['2024-06-01', null, true]]);

        $this->assertRejected('department_id', fn () => $this->create($department, '2024-04-01', isMain: true));
    }

    public function test_rejects_a_user_who_is_not_effective_over_the_whole_period(): void
    {
        $this->user = $this->user([['2024-05-01', null]]);

        $this->assertRejected('user_id', fn () => $this->create($this->department($this->set), '2024-04-01', isMain: true));
    }

    public function test_rejects_a_membership_on_days_without_a_main_membership(): void
    {
        $this->create($this->department($this->set), '2024-04-01', '2025-04-01', isMain: true);
        $other = $this->department($this->set);

        $this->assertRejected('is_main', fn () => $this->create($other));
        $this->assertRejected('is_main', fn () => $this->create($other, '2024-04-01'));
        $this->assertSame('2024-04-01', $this->create($other, '2024-04-01', '2025-04-01')->start_date->toDateString());
    }

    public function test_a_main_membership_in_another_set_does_not_count(): void
    {
        $this->create($this->department(DepartmentSet::factory()->create()), isMain: true);

        $this->assertRejected('is_main', fn () => $this->create($this->department($this->set)));
    }

    public function test_a_membership_that_is_not_main_does_not_count_as_the_main_one(): void
    {
        $this->join($this->user, $this->department($this->set));

        $this->assertRejected('is_main', fn () => $this->create($this->department($this->set)));
    }

    public function test_a_deleted_main_membership_does_not_count(): void
    {
        $this->join($this->user, $this->department($this->set), ['2020-01-01', null, true], isMain: true);

        $this->assertRejected('is_main', fn () => $this->create($this->department($this->set)));
    }

    public function test_assigns_a_post_effective_over_the_whole_membership(): void
    {
        $membership = $this->create($this->department($this->set), '2024-04-01', '2025-04-01', isMain: true);
        $companyPost = $this->companyPost($this->set, 30, [['2024-04-01', '2024-10-01'], ['2024-10-01', null]]);

        $this->assertSame($companyPost->id, app(AssignMembershipPost::class)->execute($membership->id, $companyPost->id)->company_post_id);
    }

    public function test_rejects_a_post_that_is_not_effective_over_the_whole_membership(): void
    {
        $membership = $this->create($this->department($this->set), '2024-04-01', isMain: true);
        $companyPost = $this->companyPost($this->set, 30, [['2020-01-01', '2030-01-01']]);

        $this->assertRejected('company_post_id', fn () => app(AssignMembershipPost::class)->execute($membership->id, $companyPost->id));
    }

    public function test_a_post_may_be_assigned_to_a_deleted_membership_whatever_its_period(): void
    {
        $membership = $this->join($this->user, $this->department($this->set), ['2020-01-01', null, true], isMain: true);
        $companyPost = $this->companyPost($this->set, 30, [['2024-01-01', '2024-02-01']]);

        $this->assertSame($membership->id, app(AssignMembershipPost::class)->execute($membership->id, $companyPost->id)->department_membership_id);
    }

    private function create(Department $department, string $start = '2020-01-01', ?string $end = null, bool $isMain = false): DepartmentMembership
    {
        return app(CreateDepartmentMembership::class)->execute($this->user->id, $department->id, new Period(self::day($start), $end === null ? null : self::day($end)), $isMain);
    }
}
