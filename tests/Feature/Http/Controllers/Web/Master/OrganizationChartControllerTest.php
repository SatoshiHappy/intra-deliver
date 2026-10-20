<?php

namespace Tests\Feature\Http\Controllers\Web\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 組織図(docs の 設計/共通マスタ/検索と組織図.md 1 節): ページ Master/OrganizationChart の props tree・selectedDepartment・members・highlightedUserId。
 * クエリ department・user は整数として読めなければ指定なしと同じで、木に無い組織は頂点になる。部分再読み込みでは tree を作らない。
 * 初期データ(兼務の u0009 = 総務部が主所属、人事部を兼務。退職者 u0014 = 経理部)を入れ、今日を 2026-10-10 に固定し、ルートの認可を外さずに開く。
 */
final class OrganizationChartControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_a_guest_sees_the_tree_from_the_root_with_the_root_selected(): void
    {
        $root = $this->departmentId('deliver');

        $this->get('/master/organization-chart')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Master/OrganizationChart')
            ->where('tree.id', $root)
            ->where('tree.code', 'deliver')
            ->where('selectedDepartment.id', $root)
            ->where('selectedDepartment.code', 'deliver')
            ->where('highlightedUserId', null)
        );
    }

    public function test_siblings_are_in_the_order_of_the_initial_data(): void
    {
        $this->get('/master/organization-chart')->assertInertia(fn (Assert $page) => $page
            ->has('tree.children', 4)
            ->where('tree.children.0.code', 'planning')
            ->where('tree.children.1.code', 'administration')
            ->where('tree.children.2.code', 'sales')
            ->where('tree.children.3.code', 'engineering')
            ->has('tree.children.1.children', 4)
            ->where('tree.children.1.children.0.code', 'general-affairs')
            ->where('tree.children.1.children.1.code', 'accounting')
            ->where('tree.children.1.children.2.code', 'human-resources')
            ->where('tree.children.1.children.3.code', 'quality-assurance')
        );
    }

    public function test_the_members_of_the_selected_department_include_a_concurrent_membership_with_its_post(): void
    {
        $humanResources = $this->departmentId('human-resources');
        $user = User::query()->where('code', 'u0009')->sole();
        $manager = CompanyPost::query()->where('code', 'manager')->sole();

        $this->get("/master/organization-chart?department={$humanResources}")->assertInertia(fn (Assert $page) => $page
            ->where('selectedDepartment', ['id' => $humanResources, 'code' => 'human-resources', 'name' => '人事部'])
            ->where('members', [[
                'id' => $user->id,
                'code' => 'u0009',
                'name' => '吉田 達也',
                'isMain' => false,
                'posts' => [['id' => $manager->id, 'code' => 'manager', 'name' => '部長']],
            ]])
        );
    }

    public function test_members_are_in_the_order_of_the_memberships_and_leave_out_a_retired_user(): void
    {
        $this->get('/master/organization-chart?department='.$this->departmentId('tokyo-sales'))->assertInertia(fn (Assert $page) => $page
            ->has('members', 2)
            ->where('members.0.code', 'u0002')
            ->where('members.0.isMain', true)
            ->where('members.1.code', 'u0001')
            ->where('members.1.posts', [])
        );
        $this->get('/master/organization-chart?department='.$this->departmentId('accounting'))->assertInertia(fn (Assert $page) => $page
            ->has('members', 1)
            ->where('members.0.code', 'u0010')
        );
    }

    #[TestWith(['abc'])]
    #[TestWith(['1.5'])]
    #[TestWith(['99999999'])]
    #[TestWith(['99999999999999999999'])]
    public function test_a_department_that_is_not_an_integer_or_not_in_the_tree_selects_the_root(string $department): void
    {
        $this->get("/master/organization-chart?department={$department}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedDepartment.code', 'deliver')
        );
    }

    public function test_a_department_given_as_an_array_selects_the_root(): void
    {
        $this->get('/master/organization-chart?department[]='.$this->departmentId('human-resources'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedDepartment.code', 'deliver')
        );
    }

    public function test_passes_the_user_to_highlight_or_null_when_it_is_not_an_integer(): void
    {
        $user = User::query()->where('code', 'u0009')->sole();

        $this->get("/master/organization-chart?user={$user->id}")->assertInertia(fn (Assert $page) => $page->where('highlightedUserId', $user->id));
        $this->get('/master/organization-chart?user=abc')->assertInertia(fn (Assert $page) => $page->where('highlightedUserId', null));
    }

    public function test_a_partial_reload_of_the_selection_does_not_build_the_tree(): void
    {
        $humanResources = $this->departmentId('human-resources');

        $this->get("/master/organization-chart?department={$humanResources}")->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['selectedDepartment', 'members', 'highlightedUserId'], fn (Assert $reload) => $reload
                ->missing('tree')
                ->where('selectedDepartment.id', $humanResources)
                ->has('members', 1)
            )
        );
    }

    public function test_an_authenticated_user_sees_the_chart(): void
    {
        $this->actingAs(User::query()->where('code', 'u0001')->sole())
            ->get('/master/organization-chart')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Master/OrganizationChart'));
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }
}
