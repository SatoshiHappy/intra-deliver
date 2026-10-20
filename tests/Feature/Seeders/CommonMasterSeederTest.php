<?php

namespace Tests\Feature\Seeders;

use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use App\Models\Authz\UserRole;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Models\Master\DepartmentSet;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Queries\Authz\RoleQuery;
use App\Queries\Master\DepartmentTreeQuery;
use App\Queries\Master\MembershipQuery;
use App\Queries\Master\TermQuery;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 共通マスタの初期データ(架空の「株式会社デリバー商事」)を空のテスト用 DB に入れ、段 A の読み取りで日ごとの形を確かめる。
 * 出来事 = 2025-04-01 の組織変更(名称の履歴・第二営業部の新設・品質保証部の付け替え)と人事異動(異動・昇格)、2025-10-01 の退職、
 * 2026-03-31 で終える付与、2026-11-01 の入社。
 */
final class CommonMasterSeederTest extends TransactionalTestCase
{
    public function test_seeds_the_counts_of_the_fictional_company(): void
    {
        $this->assertSame(
            ['locales' => 2, 'sets' => 1, 'departments' => 13, 'posts' => 7, 'users' => 17, 'memberships' => 21, 'membership_posts' => 16, 'roles' => 4, 'sub_roles' => 2, 'grants' => 21],
            [
                'locales' => Locale::query()->count(),
                'sets' => DepartmentSet::query()->count(),
                'departments' => Department::query()->count(),
                'posts' => CompanyPost::query()->count(),
                'users' => User::query()->count(),
                'memberships' => DepartmentMembership::query()->count(),
                'membership_posts' => DepartmentMembershipPost::query()->count(),
                'roles' => Role::query()->count(),
                'sub_roles' => RoleInclusion::query()->count(),
                'grants' => UserRole::query()->count(),
            ],
        );
    }

    public function test_the_tree_and_the_names_change_on_the_reorganization(): void
    {
        $before = $this->parentsOn('2024-06-01');
        $after = $this->parentsOn('2025-06-01');

        $this->assertArrayNotHasKey('sales-2', $before);
        $this->assertSame('engineering', $before['quality-assurance']);
        $this->assertSame(['deliver' => null, 'sales-1' => 'sales', 'tokyo-sales' => 'sales-1'], array_intersect_key($before, array_flip(['deliver', 'sales-1', 'tokyo-sales'])));
        $this->assertSame('sales', $after['sales-2']);
        $this->assertSame('administration', $after['quality-assurance']);
        $this->assertCount(13, $after);
        $this->assertSame(['営業部', 'Sales Dept.'], $this->departmentNamesOn('sales-1', '2025-03-31'));
        $this->assertSame(['第一営業部', 'Sales Dept. 1'], $this->departmentNamesOn('sales-1', '2025-04-01'));
        $this->assertSame(['株式会社デリバー商事', 'Deliver Trading Inc.'], $this->departmentNamesOn('deliver', '2026-10-04'));
    }

    public function test_concurrent_posts_transfers_and_promotions(): void
    {
        $this->assertSame([['general-affairs', true, ['manager']], ['human-resources', false, ['manager']]], $this->membershipsOn('u0009', '2025-06-01'));
        $this->assertSame([['development', true, []]], $this->membershipsOn('u0013', '2025-03-31'));
        $this->assertSame([['sales-1', true, []]], $this->membershipsOn('u0013', '2025-04-01'));
        $this->assertSame([['development', true, ['chief']]], $this->membershipsOn('u0012', '2025-03-31'));
        $this->assertSame([['development', true, ['section-chief']]], $this->membershipsOn('u0012', '2025-04-01'));
        $this->assertSame([['sales-1', true, ['assistant-manager']]], $this->membershipsOn('u0016', '2024-06-01'));
        $this->assertSame([['sales-2', true, ['manager']]], $this->membershipsOn('u0016', '2025-06-01'));
    }

    public function test_the_retired_user_and_the_user_joining_later(): void
    {
        $this->assertNotNull($this->userOn('u0014', '2025-09-30'));
        $this->assertNull($this->userOn('u0014', '2025-10-01'));
        $this->assertSame([], $this->membershipsOn('u0014', '2025-10-01'));
        $this->assertSame(['general_user'], $this->rolesOn('u0014', '2025-09-30'));
        $this->assertSame([], $this->rolesOn('u0014', '2025-10-01'));

        $this->assertNull($this->userOn('u0015', '2026-10-04'));
        $this->assertSame([], $this->membershipsOn('u0015', '2026-10-04'));
        $this->assertSame([], $this->rolesOn('u0015', '2026-10-04'));
        $this->assertSame('林 翔太', $this->userOn('u0015', '2026-11-01'));
        $this->assertSame([['sales-2', true, []]], $this->membershipsOn('u0015', '2026-11-01'));
        $this->assertSame(['general_user'], $this->rolesOn('u0015', '2026-11-01'));
    }

    public function test_roles_follow_sub_roles_and_the_ended_grant(): void
    {
        $this->assertEqualsCanonicalizing(['system_admin', 'master_admin', 'general_user'], $this->rolesOn('u0004', '2026-10-04'));
        $this->assertEqualsCanonicalizing(['workflow_admin', 'general_user'], $this->rolesOn('u0009', '2026-03-31'));
        $this->assertSame(['general_user'], $this->rolesOn('u0009', '2026-04-01'));
        $this->assertEqualsCanonicalizing(['workflow_admin', 'general_user'], $this->rolesOn('u0002', '2026-10-04'));
    }

    public function test_the_approval_route_follows_the_upper_departments_and_their_posts(): void
    {
        $date = '2026-10-04';
        [[$department]] = $this->membershipsOn('u0001', $date);
        $upper = app(DepartmentTreeQuery::class)->ancestors(Department::query()->where('code', $department)->sole()->id, self::day($date))->pluck('code')->all();

        $this->assertSame('tokyo-sales', $department);
        $this->assertEqualsCanonicalizing(['sales-1', 'sales', 'deliver'], $upper);
        $this->assertSame(['u0002'], $this->holdersOn('tokyo-sales', 'section-chief', $date));
        $this->assertSame(['u0003'], $this->holdersOn('sales-1', 'manager', $date));
        $this->assertSame(['u0006'], $this->holdersOn('sales', 'division-head', $date));
    }

    public function test_the_four_sample_users_come_first(): void
    {
        $this->assertSame(['u0001', 'u0002', 'u0003', 'u0004'], User::query()->orderBy('sort_key')->orderBy('code')->limit(4)->pluck('code')->all());
        $this->assertSame([[], ['section-chief'], ['manager']], [
            $this->membershipsOn('u0001', '2026-10-04')[0][2],
            $this->membershipsOn('u0002', '2026-10-04')[0][2],
            $this->membershipsOn('u0003', '2026-10-04')[0][2],
        ]);
        $this->assertContains('system_admin', $this->rolesOn('u0004', '2026-10-04'));
    }

    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    /**
     * d の組織ツリーの、組織のコード → 親の組織のコード(頂点は null)。
     *
     * @return array<string, string|null>
     */
    private function parentsOn(string $date): array
    {
        $tree = app(DepartmentTreeQuery::class)->tree(DepartmentSet::query()->sole()->id, self::day($date));
        $codes = $tree->pluck('code', 'id')->all();

        return $tree->mapWithKeys(fn (Department $department) => [
            $department->code => $codes[$department->inclusionsAsChild->first()?->parent_department_id] ?? null,
        ])->all();
    }

    /**
     * @return array{string|null, string|null}
     */
    private function departmentNamesOn(string $code, string $date): array
    {
        $id = Department::query()->where('code', $code)->sole()->id;

        return [
            app(TermQuery::class)->departmentTermOn($id, self::day($date), 'ja')?->departmentTermTranslations->first()?->name,
            app(TermQuery::class)->departmentTermOn($id, self::day($date), 'en')?->departmentTermTranslations->first()?->name,
        ];
    }

    private function userOn(string $code, string $date): ?string
    {
        return app(TermQuery::class)->userTermOn(User::query()->where('code', $code)->sole()->id, self::day($date), 'ja')?->userTermTranslations->first()?->name;
    }

    /**
     * ユーザーの d の所属: [組織のコード, 主所属か, 役職のコード]。
     *
     * @return list<array{string, bool, list<string>}>
     */
    private function membershipsOn(string $code, string $date): array
    {
        return array_values(app(MembershipQuery::class)->membershipsOn(User::query()->where('code', $code)->sole()->id, self::day($date))
            ->sortByDesc('is_main')
            ->map(fn (DepartmentMembership $membership) => [
                (string) $membership->department?->code,
                $membership->is_main,
                array_values($membership->departmentMembershipPosts->map(fn (DepartmentMembershipPost $post) => (string) $post->companyPost?->code)->all()),
            ])->all());
    }

    /**
     * @return list<string>
     */
    private function rolesOn(string $code, string $date): array
    {
        return array_values(app(RoleQuery::class)->rolesOn(User::query()->where('code', $code)->sole()->id, self::day($date))->pluck('code')->all());
    }

    /**
     * d に組織で役職に就いているユーザーのコード。
     *
     * @return list<string>
     */
    private function holdersOn(string $department, string $post, string $date): array
    {
        return array_values(User::query()->orderBy('code')->get()
            ->filter(fn (User $user) => in_array([$department, true, [$post]], $this->membershipsOn($user->code, $date), true)
                || in_array([$department, false, [$post]], $this->membershipsOn($user->code, $date), true))
            ->pluck('code')->all());
    }
}
