<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\AddSubRole;
use App\Actions\Authz\CreateRole;
use App\Actions\Authz\GrantRole;
use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use App\Models\Authz\RoleTranslation;
use App\Models\Authz\UserRole;
use App\Models\Master\User;
use App\Support\Period;
use DateTimeInterface;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ロールを作る・サブロールを付ける・ロールを付与する(docs の 設計/共通マスタ/ロールと認可/ロール.md 1〜3 節の業務処理で止める規則:
 * サブロールで循環を作らない、付与が d に有効ならユーザーも d に有効。既定の期間 = 登録日から、ユーザーが続けて有効な間)。
 */
final class RoleActionsTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use BuildsCommonMaster;

    public function test_creates_a_role_with_display_names_per_locale(): void
    {
        $role = app(CreateRole::class)->execute('general', 'general_user', ['ja' => '一般利用者', 'en' => 'General user'], 'basic', '全員に付ける', 5);

        $this->assertSame(['general', 'general_user', 'basic', '全員に付ける', 5], [$role->code, $role->name, $role->category, $role->notes, $role->sort_key]);
        $this->assertEqualsCanonicalizing(['一般利用者', 'General user'], $role->roleTranslations()->get()->map(fn (RoleTranslation $translation) => $translation->display_name)->all());
    }

    public function test_rejects_a_display_name_in_an_unknown_locale(): void
    {
        $roles = Role::query()->count();

        $this->assertRejected('locale', fn () => app(CreateRole::class)->execute('general', 'general_user', ['xx' => '一般']));
        $this->assertSame($roles, Role::query()->count());
    }

    public function test_adds_sub_roles_in_a_tree_or_a_lattice(): void
    {
        [$top, $middle, $bottom] = Role::factory()->count(3)->create()->all();
        $this->addSubRole($top, $middle);
        $this->addSubRole($middle, $bottom);

        $this->assertSame($bottom->id, $this->addSubRole($top, $bottom)->child_role_id);
    }

    public function test_rejects_a_sub_role_that_closes_a_cycle(): void
    {
        [$top, $middle, $bottom] = Role::factory()->count(3)->create()->all();
        $this->addSubRole($top, $middle);
        $this->addSubRole($middle, $bottom);

        $this->assertRejected('child_role_id', fn () => $this->addSubRole($middle, $top));
        $this->assertRejected('child_role_id', fn () => $this->addSubRole($bottom, $top));
    }

    public function test_grants_for_an_explicit_period_within_the_user(): void
    {
        $user = $this->user([['2020-01-01', '2026-01-01']]);

        $grant = $this->grant($user, new Period(self::day('2024-04-01'), self::day('2025-04-01')));

        $this->assertSame(['2024-04-01', '2025-04-01', false], $this->periodOf($grant));
    }

    public function test_rejects_an_explicit_period_beyond_the_user_instead_of_cutting_it(): void
    {
        $user = $this->user([['2020-01-01', '2025-01-01'], ['2025-01-01', '2025-02-01', true], ['2025-02-01', null]]);

        $this->assertRejected('user_id', fn () => $this->grant($user, new Period(self::day('2024-04-01'))));
        $this->assertRejected('user_id', fn () => $this->grant($user, new Period(self::day('2019-12-31'), self::day('2020-02-01'))));
    }

    public function test_the_default_period_runs_from_the_registration_date_while_the_user_stays_effective(): void
    {
        $forever = $this->user([['2020-01-01', '2024-06-01'], ['2024-06-01', null]]);
        $leaving = $this->user([['2020-01-01', '2025-04-01']]);
        $suspended = $this->user([['2020-01-01', '2024-10-01'], ['2024-10-01', '2025-01-01', true], ['2025-01-01', null]]);

        $this->assertSame(['2024-04-01', null, false], $this->periodOf($this->grant($forever, self::day('2024-04-01'))));
        $this->assertSame(['2024-04-01', '2025-04-01', false], $this->periodOf($this->grant($leaving, self::day('2024-04-01'))));
        $this->assertSame(['2024-04-01', '2024-10-01', false], $this->periodOf($this->grant($suspended, self::day('2024-04-01'))));
    }

    public function test_rejects_the_default_period_when_the_user_is_not_effective_on_the_registration_date(): void
    {
        $user = $this->user([['2020-01-01', '2024-04-01'], ['2024-04-01', null, true]]);

        $this->assertRejected('user_id', fn () => $this->grant($user, self::day('2024-04-01')));
        $this->assertRejected('user_id', fn () => $this->grant($this->user([['2024-05-01', null]]), self::day('2024-04-01')));
    }

    private function addSubRole(Role $parent, Role $child): RoleInclusion
    {
        return app(AddSubRole::class)->execute($parent->id, $child->id);
    }

    private function grant(User $user, Period|DateTimeInterface $term): UserRole
    {
        return app(GrantRole::class)->execute($user->id, Role::factory()->create()->id, $term);
    }

    /**
     * @return array{string|null, string|null, bool}
     */
    private function periodOf(UserRole $grant): array
    {
        return [$grant->start_date->toDateString(), $grant->end_date?->toDateString(), $grant->is_deleted];
    }
}
