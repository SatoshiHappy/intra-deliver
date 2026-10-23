<?php

namespace Tests\Feature\Queries\Authz;

use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use App\Queries\Authz\RoleQuery;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザーそれぞれが d に持つロール(docs の 設計/共通マスタ/ロールと認可/ロール.md 2 節・3 節)と、1 人の口・サブロールの口の並び
 * (一覧の規則のソートキー、コードの順)。まとめの口は、ユーザーごとのロールと並びが 1 人の口と同じで、ユーザーを取り違えない。
 *
 * ロール = 親 P(ソートキー 3)> 子 Q(ソートキー 1)> 孫 S(ソートキー 2)、ほかに T(ソートキー 0)。
 */
final class RoleQueryOfEachTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    /** @var array<string, Role> */
    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['P' => 3, 'Q' => 1, 'S' => 2, 'T' => 0] as $name => $sortKey) {
            $this->roles[$name] = Role::factory()->create(['sort_key' => $sortKey]);
        }

        $this->subRole('P', 'Q');
        $this->subRole('Q', 'S');
    }

    public function test_reads_the_roles_of_each_user_in_the_order_of_one_user(): void
    {
        $parentHolder = $this->user();
        $this->grant($parentHolder, $this->roles['P']);
        $this->grant($parentHolder, $this->roles['T']);
        $childHolder = $this->user();
        $this->grant($childHolder, $this->roles['Q']);
        $nobody = $this->user();
        $retired = $this->user([['2020-01-01', '2024-04-01']]);
        $this->grant($retired, $this->roles['P']);
        $userIds = [$childHolder->id, $parentHolder->id, $nobody->id, $retired->id];

        $roleIds = app(RoleQuery::class)->roleIdsOfEach($userIds, self::day(self::DATE));

        $this->assertSame($userIds, array_keys($roleIds));
        $this->assertSame($this->idsOf(['T', 'Q', 'S', 'P']), $roleIds[$parentHolder->id]);
        $this->assertSame($this->idsOf(['Q', 'S']), $roleIds[$childHolder->id]);
        $this->assertSame([], $roleIds[$nobody->id]);
        $this->assertSame([], $roleIds[$retired->id]);

        foreach ($userIds as $userId) {
            $alone = app(RoleQuery::class)->rolesOn($userId, self::day(self::DATE));
            $this->assertSame($alone->modelKeys(), $roleIds[$userId]);
        }
    }

    public function test_the_roles_of_one_user_keep_only_the_columns_of_the_role(): void
    {
        $user = $this->user();
        $this->grant($user, $this->roles['T']);

        $role = app(RoleQuery::class)->rolesOn($user->id, self::day(self::DATE))->sole();

        $columns = array_keys(Role::query()->findOrFail($this->roles['T']->id)->getAttributes());
        $this->assertSame($columns, array_keys($role->getAttributes()));
    }

    public function test_the_sub_roles_are_in_order_without_the_role_itself_unless_in_a_cycle(): void
    {
        $this->assertSame($this->idsOf(['Q', 'S']), app(RoleQuery::class)->subRoleIds($this->roles['P']->id));
        $this->assertSame([], app(RoleQuery::class)->subRoleIds($this->roles['T']->id));

        $this->subRole('S', 'P');

        $this->assertSame($this->idsOf(['Q', 'S', 'P']), app(RoleQuery::class)->subRoleIds($this->roles['P']->id));
    }

    public function test_no_user_reads_nothing_without_a_query(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $roleIds = app(RoleQuery::class)->roleIdsOfEach([], self::day(self::DATE));
        DB::disableQueryLog();

        $this->assertSame([], $roleIds);
        $this->assertSame([], DB::getQueryLog());
    }

    private function subRole(string $parent, string $child): void
    {
        RoleInclusion::factory()->create([
            'parent_role_id' => $this->roles[$parent]->id,
            'child_role_id' => $this->roles[$child]->id,
        ]);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function idsOf(array $names): array
    {
        return array_map(fn (string $name) => $this->roles[$name]->id, $names);
    }
}
