<?php

namespace Tests\Feature\Queries\Authz;

use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\Role;
use App\Models\Authz\UserRole;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\UserTerm;
use App\Queries\Authz\Api\Authorization;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Queries\Authz\AuthorizationQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 使い回さない区間 {@see AuthorizationReuse::without()} の中の判定を求める者の事実(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 4 節。
 * 規則 = 同じフォルダの 判定の使い回し.md 3.4 の表の事実の列)。事実を覚えた後に許可の元を外し、どこで判定するかで今の事実か覚えた事実かを見る。
 *
 * @phpstan-type Place 'outside'|'within'|'without'|'without in within'|'without in without'|'within in without'
 */
final class AuthorizationReuseFactsTest extends TransactionalTestCase
{
    use ArrangesAuthorizationReuse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeAllowedUri();
        $role = Role::factory()->create();
        $this->grant($this->user, $role);
        AuthzSubjectCondition::query()->where('authz_subject_group_id', $this->mine->id)
            ->update(['user_id' => null, 'role_id' => $role->id]);
        $this->resetRequestScope();
        $this->assertTrue($this->allows());
    }

    /**
     * 外した後に判定する所 → 許すか。
     *
     * @return array<string, array{Place, bool}>
     */
    public static function placesAfterRevoking(): array
    {
        return [
            '表の 1 行: まとまりの外は覚えた事実' => ['outside', true],
            '表の 5 行: まとまりの中は覚えた事実' => ['within', true],
            '表の 2 行: 区間の中は今の事実' => ['without', false],
            '表の 2 行: まとまりの中の区間' => ['without in within', false],
            '表の 2 行: 入れ子の区間' => ['without in without', false],
            '表の 2 行: 区間の中で開いたまとまり' => ['within in without', false],
        ];
    }

    /**
     * @param  Place  $place
     */
    #[DataProvider('placesAfterRevoking')]
    public function test_a_revoked_role_takes_effect_only_inside_the_bypass(string $place, bool $allowed): void
    {
        $this->revokeRole();
        $decide = fn (): bool => $this->allows();
        $reuse = $this->reuse();

        $result = match ($place) {
            'outside' => $decide(),
            'within' => $reuse->within($decide),
            'without' => $reuse->without($decide),
            'without in within' => $reuse->within(fn (): bool => $reuse->without($decide)),
            'without in without' => $reuse->without(fn (): bool => $reuse->without($decide)),
            'within in without' => $reuse->without(fn (): bool => $reuse->within($decide)),
        };

        $this->assertSame($allowed, $result);
    }

    /** 表の 2 行: 区間の中で事実の元を書くと(捨てる場面)、同じ区間の後の判定は書いた後の事実で判定する */
    public function test_inside_one_bypass_a_write_makes_the_facts_again(): void
    {
        $results = $this->reuse()->without(function () {
            $before = $this->allows();
            $this->revokeRole();

            return [$before, $this->allows()];
        });

        $this->assertSame([true, false], $results);
    }

    /** 表の 2 行: 入れ子の区間に入ると(捨てる場面)、書き換えずに判定しても事実を作り直す */
    public function test_entering_a_nested_bypass_makes_the_facts_again(): void
    {
        $this->resetRequestScope();
        $withFacts = $this->queriesOf(fn () => $this->allows());

        $counts = $this->reuse()->without(fn () => [
            $this->queriesOf(fn () => $this->allows()),
            $this->reuse()->without(fn () => $this->queriesOf(fn () => $this->allows())),
        ]);

        $this->assertSame([$withFacts, $withFacts], $counts);
    }

    /** 表の 2 行: 区間の中の事実は判定を求める者ごと(ある人の事実を、別の人の判定に使わない) */
    public function test_inside_one_bypass_the_facts_are_per_requester(): void
    {
        $other = new Requester($this->user()->id, null);
        $decideOther = fn (): bool => app(Authorization::class)
            ->allowsUri($other, $this->uri, 'read', self::day(self::DATE));

        $results = $this->reuse()->without(fn () => [$this->allows(), $decideOther(), $this->allows()]);

        $this->assertSame([true, false, true], $results);
    }

    /** 表の 2 行: 区間の中で要求の寿命を区切ると、その後の判定は事実を今の DB から作り直す */
    public function test_inside_the_bypass_the_end_of_a_request_scope_makes_the_facts_again(): void
    {
        $this->resetRequestScope();
        $withFacts = $this->queriesOf(fn () => $this->allows());

        $counts = $this->reuse()->without(function () {
            $first = $this->queriesOf(fn () => $this->allows());
            $this->resetRequestScope();

            return [$first, $this->queriesOf(fn () => $this->allows())];
        });

        $this->assertSame([$withFacts, $withFacts], $counts);
    }

    /** 表の 6 行: まとまりの中で判定の途中に捨てる場面があっても、事実は要求の中の覚え(覚えた後に外したロールは効かない) */
    public function test_a_discard_in_the_middle_of_deciding_within_keeps_the_remembered_facts(): void
    {
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && str_contains($query->sql, '"authz_resources" where "uri" in')) {
                $armed = false;
                $this->revokeRole();
            }
        });

        $results = $this->reuse()->within(fn () => [
            $this->allows(),
            $this->queriesOf(fn () => $this->assertTrue($this->allows())),
        ]);

        $this->assertTrue($results[0]);
        $this->assertGreaterThan(0, $results[1]);
    }

    /** 区間の中でだけ判定した判定を求める者は、区間の外の最初の判定で、その時の DB から事実を作る */
    public function test_a_requester_decided_only_inside_the_bypass_gets_the_facts_at_the_first_decision_outside(): void
    {
        $this->resetRequestScope();
        $this->assertTrue($this->reuse()->without(fn () => $this->allows()));
        $this->revokeRole();

        $this->assertFalse($this->allows());
    }

    /** 事実の元が所属でも、区間の中だけ今の事実で判定する */
    public function test_a_left_department_takes_effect_only_inside_the_bypass(): void
    {
        $department = $this->department(DepartmentSet::factory()->create(['is_default' => true]), isRoot: true);
        $this->join($this->user, $department, isMain: true);
        AuthzSubjectCondition::query()->where('authz_subject_group_id', $this->mine->id)
            ->update(['role_id' => null, 'department_id' => $department->id]);
        $this->resetRequestScope();
        $this->assertTrue($this->allows());
        DepartmentMembership::query()->where('user_id', $this->user->id)->delete();

        $this->assertSame([false, true], [$this->reuse()->without(fn () => $this->allows()), $this->allows()]);
    }

    /** 表の 3 行と 2 行: リソースグループ id を渡す判定も、区間の中だけ今の事実で判定する */
    public function test_deciding_by_the_resource_group_id_uses_the_current_facts_only_inside_the_bypass(): void
    {
        $query = app(AuthorizationQuery::class);
        $decide = fn () => $query->allows($this->requester, $this->group->id, 'read', self::day(self::DATE));
        $this->revokeRole();

        $this->assertSame([true, false], [$this->reuse()->within($decide), $this->reuse()->without($decide)]);
    }

    /** 区間で作った事実は区間の外に持ち出さない(区間の後の外の判定は、区間の前に覚えた事実のまま) */
    public function test_the_facts_made_inside_the_bypass_do_not_leave_it(): void
    {
        $this->revokeRole();

        $this->assertSame([false, true], [$this->reuse()->without(fn () => $this->allows()), $this->allows()]);
    }

    /** 例外で区間を出た後も、外の判定は覚えた事実のまま */
    public function test_after_an_exception_leaves_the_bypass_the_remembered_facts_are_used(): void
    {
        $this->revokeRole();
        $thrown = null;

        try {
            $this->reuse()->without(function () {
                $this->assertFalse($this->allows());

                throw new RuntimeException('判定の後の失敗');
            });
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertSame('判定の後の失敗', $thrown->getMessage());
        $this->assertTrue($this->allows());
    }

    /** 事実を使う口も、区間の中だけ今の事実で答える(対象者に合うか) */
    public function test_matching_ids_answers_with_the_current_facts_only_inside_the_bypass(): void
    {
        $membership = app(SubjectGroupMembership::class);
        $matching = fn () => $membership->matchingIds($this->requester, [$this->mine->id], self::day(self::DATE));
        $this->revokeRole();

        $this->assertSame([[], [$this->mine->id]], [$this->reuse()->without($matching), $matching()]);
    }

    /** 事実を使う口も、区間の中だけ今の事実で答える(d に有効なユーザー) */
    public function test_the_effective_user_is_the_current_one_only_inside_the_bypass(): void
    {
        $membership = app(SubjectGroupMembership::class);
        $effective = fn () => $membership->effectiveUserId($this->requester, self::day(self::DATE));
        $this->assertSame($this->user->id, $effective());
        UserTerm::query()->where('user_id', $this->user->id)->update(['end_date' => '2026-11-01']);

        $this->assertSame([null, $this->user->id], [$this->reuse()->without($effective), $effective()]);
    }

    /**
     * 許可の元のロールを外す(クエリビルダーの一括の書き込み = モデルのイベントを通らない)。
     */
    private function revokeRole(): void
    {
        UserRole::query()->where('user_id', $this->user->id)->delete();
    }
}
