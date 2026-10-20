<?php

namespace Tests\Feature\Actions\Master;

use App\Actions\Authz\GrantRole;
use App\Actions\Master\CreateDepartmentMembership;
use App\Actions\Master\InvalidateUserTerm;
use App\Actions\Master\SplitUserTerm;
use App\Models\Authz\Role;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Queries\Authz\UserRoleReferences;
use App\Queries\Master\TermQuery;
use App\Queries\Master\UserReferences;
use App\Support\Period;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザーの期間の行を無効にする(docs の 設計/共通マスタ/共通の規則.md 5.5 の 3 行目: マスタの有効な日を減らすとき、それを指す期間に従う行の
 * 有効な日がはみ出さない)。Master の外の行(ロールの付与)は、依存の逆転(UserReferences)で確かめる。
 */
final class InvalidateUserTermTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use BuildsCommonMaster;

    public function test_the_references_of_authz_are_registered_for_master(): void
    {
        $registered = array_map(fn (UserReferences $references) => $references::class, iterator_to_array(app()->tagged(UserReferences::TAG), false));

        $this->assertContains(UserRoleReferences::class, $registered);
    }

    public function test_a_user_retires_after_the_membership_and_the_grant_end_on_the_retirement_date(): void
    {
        $user = $this->user([['2024-04-01', null]]);
        $until = new Period(self::day('2024-04-01'), self::day('2025-10-01'));
        app(CreateDepartmentMembership::class)->execute($user->id, $this->department(DepartmentSet::factory()->create())->id, $until, isMain: true);
        app(GrantRole::class)->execute($user->id, Role::factory()->create()->id, $until);
        app(SplitUserTerm::class)->execute($user->id, self::day('2025-10-01'));

        app(InvalidateUserTerm::class)->execute($user->id, self::day('2025-10-01'));

        $this->assertSame([['2024-04-01', '2025-10-01', false], ['2025-10-01', null, true]], $this->termsOf($user));
        $this->assertNotNull(app(TermQuery::class)->userTermOn($user->id, self::day('2025-09-30'), 'ja'));
        $this->assertNull(app(TermQuery::class)->userTermOn($user->id, self::day('2025-10-01'), 'ja'));
    }

    public function test_rejects_when_a_membership_would_overhang(): void
    {
        $user = $this->retiringUser();
        $this->join($user, $this->department(DepartmentSet::factory()->create()), ['2024-04-01', '2025-10-02']);

        $this->assertRejected('memberships', fn () => app(InvalidateUserTerm::class)->execute($user->id, self::day('2025-10-01')));
        $this->assertSame([['2024-04-01', '2025-10-01', false], ['2025-10-01', null, false]], $this->termsOf($user));
    }

    public function test_rejects_when_a_grant_of_authz_would_overhang(): void
    {
        $user = $this->retiringUser();
        $this->grant($user, Role::factory()->create(), ['2024-04-01', null]);

        $this->assertRejected('references', fn () => app(InvalidateUserTerm::class)->execute($user->id, self::day('2025-10-01')));
        $this->assertSame([['2024-04-01', '2025-10-01', false], ['2025-10-01', null, false]], $this->termsOf($user));
    }

    public function test_rejects_a_term_in_the_middle_when_a_row_spans_across_it(): void
    {
        $user = $this->user([['2024-04-01', '2025-04-01'], ['2025-04-01', '2025-05-01'], ['2025-05-01', null]]);
        $this->grant($user, Role::factory()->create(), ['2025-03-01', '2025-06-01']);
        $this->grant($user, Role::factory()->create(), ['2025-05-01', null]);

        $this->assertRejected('references', fn () => app(InvalidateUserTerm::class)->execute($user->id, self::day('2025-04-01')));
    }

    public function test_deleted_rows_do_not_count(): void
    {
        $user = $this->retiringUser();
        $this->join($user, $this->department(DepartmentSet::factory()->create()), ['2024-04-01', null, true]);
        $this->grant($user, Role::factory()->create(), ['2024-04-01', null, true]);

        $this->assertTrue(app(InvalidateUserTerm::class)->execute($user->id, self::day('2025-10-01'))->is_deleted);
    }

    public function test_rejects_a_date_where_no_term_starts(): void
    {
        $user = $this->user([['2024-04-01', null]]);

        $this->assertRejected('date', fn () => app(InvalidateUserTerm::class)->execute($user->id, self::day('2025-10-01')));
        $this->assertSame([['2024-04-01', null, false]], $this->termsOf($user));
    }

    private function retiringUser(): User
    {
        return $this->user([['2024-04-01', '2025-10-01'], ['2025-10-01', null]]);
    }

    /**
     * @return list<array{string, string|null, bool}>
     */
    private function termsOf(User $user): array
    {
        return array_values($user->userTerms()->orderBy('start_date')->get()
            ->map(fn (UserTerm $term) => [$term->start_date->toDateString(), $term->end_date?->toDateString(), $term->is_deleted])->all());
    }
}
