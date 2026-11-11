<?php

namespace Tests\Feature\Queries\Authz;

use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Master\DepartmentSet;
use App\Queries\Authz\Api\Authorization;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationMemo;
use App\Queries\Authz\AuthorizationQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 判定の使い回し {@see AuthorizationReuse} の表の各行・組の部分・捨てる場面・寿命(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 4 節。
 * 規則 = 同じフォルダの 判定の使い回し.md 3.4)。
 */
final class AuthorizationReuseTest extends TransactionalTestCase
{
    use ArrangesAuthorizationReuse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeAllowedUri();
    }

    /** 表の 1 行: まとまりの外では毎回判定する */
    public function test_outside_it_decides_every_time(): void
    {
        $first = $this->queriesOf(fn () => $this->allows());
        $second = $this->queriesOf(fn () => $this->allows());

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $second);
    }

    /** 表の 4・5 行: 中の同じ組は、最初に判定して覚え、2 回目は覚えを使う */
    public function test_within_the_same_pair_is_decided_once(): void
    {
        $outside = $this->queriesOf(fn () => $this->allows());
        [$first, $second] = $this->reuse()->within(fn () => [
            $this->queriesOf(fn () => $this->assertTrue($this->allows())),
            $this->queriesOf(fn () => $this->assertTrue($this->allows())),
        ]);

        $this->assertSame([$outside, 0], [$first, $second]);
    }

    /** 表の 2 行: 使い回さない区間の中は、外のまとまりの中でも、中で開いたまとまりでも判定し、覚えない。入る時に外の覚えを捨てる */
    public function test_inside_the_bypass_it_decides_and_does_not_remember(): void
    {
        $counts = $this->reuse()->within(fn () => [
            'before' => $this->queriesOf(fn () => $this->allows()),
            'bypassed' => $this->reuse()->without(fn () => [
                $this->queriesOf(fn () => $this->allows()),
                $this->reuse()->within(fn () => $this->queriesOf(fn () => $this->allows())),
            ]),
            'after' => $this->queriesOf(fn () => $this->allows()),
        ]);

        $this->assertSame([$counts['before'], $counts['before']], $counts['bypassed']);
        $this->assertSame($counts['before'], $counts['after']);
    }

    /** 表の 3 行: リソースグループ id を渡す判定は、中でも毎回判定する */
    public function test_deciding_by_the_resource_group_id_is_not_reused(): void
    {
        $query = app(AuthorizationQuery::class);
        $byGroup = fn () => $query->allows($this->requester, $this->group->id, 'read', self::day(self::DATE));

        $counts = $this->reuse()->within(fn () => [$this->queriesOf($byGroup), $this->queriesOf($byGroup)]);

        $this->assertGreaterThan(0, $counts[0]);
        $this->assertSame($counts[0], $counts[1]);
    }

    /**
     * 表の 5 行(組の部分が違う): 覚えた後に、ユーザー・選んだ組織・接続元・URI・基準日・アクションを 1 つだけ変えた判定は、判定し直し、その組の結果を返す。
     */
    public function test_a_pair_that_differs_in_one_part_is_decided_again(): void
    {
        $department = $this->department(DepartmentSet::factory()->create());
        $unpermitted = $this->unpermittedUri();
        $parts = [
            'user(許可の無い人)' => [new Requester($this->user()->id, null), $this->uri, self::DATE, 'read', false],
            'user(許可のある人)' => [$this->permittedOtherUser(), $this->uri, self::DATE, 'read', true],
            'department' => [$this->requesterIn($department->id), $this->uri, self::DATE, 'read', true],
            'ipv4' => [new Requester($this->user->id, '192.168.0.1'), $this->uri, self::DATE, 'read', true],
            'uri' => [$this->requester, $unpermitted, self::DATE, 'read', false],
            'date' => [$this->requester, $this->uri, '2026-11-12', 'read', true],
            'action' => [$this->requester, $this->uri, self::DATE, 'admin', false],
        ];

        foreach ($parts as $part => [$requester, $uri, $date, $action, $expected]) {
            $decide = fn () => app(Authorization::class)->allowsUri($requester, $uri, $action, self::day($date));
            $decide();
            [$queries, $result] = $this->reuse()->within(function () use ($decide) {
                $this->allows();

                return [$this->queriesOf($decide), $decide()];
            });

            $this->assertGreaterThan(0, $queries, $part);
            $this->assertSame($expected, $result, $part);
        }
    }

    /**
     * 表の 4・5 行の混ざり: まとめた判定に覚えた URI と覚えていない URI を渡すと、URI でリソースを引く問い合わせに覚えていない URI だけを渡し、
     * どちらもその組の結果を返す(問い合わせの数は URI の数によらないので、束縛の値で確かめる)。
     */
    public function test_a_batch_mixes_remembered_and_unremembered_uris(): void
    {
        $unpermitted = $this->unpermittedUri();
        $date = self::day(self::DATE);

        $asked = $this->reuse()->within(function () use ($unpermitted, $date) {
            $this->allows();
            $log = $this->queryLogOf(function () use ($unpermitted, $date) {
                $results = app(AuthorizationQuery::class)
                    ->allowsEachUri($this->requester, [$this->uri, $unpermitted], 'read', $date);
                $this->assertSame([$this->uri => true, $unpermitted => false], $results);
            });
            $byUri = fn (array $query) => str_contains($query['query'], 'from "authz_resources" where "uri" in');
            $resourceQueries = array_filter($log, $byUri);

            return array_merge(...array_column($resourceQueries, 'bindings'));
        });

        $this->assertSame([$unpermitted], $asked);
    }

    /** 表の 6 行: 判定の途中に書き込みがあれば、その判定の結果は覚えない(まとまりを開いた直後の最初の判定 = 覚えが空) */
    public function test_a_write_in_the_middle_of_deciding_keeps_its_result_out(): void
    {
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && str_contains($query->sql, '"authz_resources" where "uri" in')) {
                $armed = false;
                $this->deny();
            }
        });

        $results = $this->reuse()->within(fn () => [
            $this->allows(),
            $this->queriesOf(fn () => $this->allows()),
            $this->allows(),
        ]);

        $this->assertGreaterThan(0, $results[1]);
        $this->assertFalse($results[2]);
    }

    public function test_a_write_by_the_query_builder_within_makes_deciding_again_see_it(): void
    {
        $results = $this->reuse()->within(function () {
            $before = $this->allows();
            $this->deny();

            return [$before, $this->allows()];
        });

        $this->assertSame([true, false], $results);
    }

    public function test_a_rollback_within_makes_deciding_again_see_the_rolled_back_settings(): void
    {
        $results = $this->reuse()->within(function () {
            DB::beginTransaction();
            $this->deny();
            $inside = $this->allows();
            DB::rollBack();

            return [$inside, $this->allows()];
        });

        $this->assertSame([false, true], $results);
    }

    public function test_committing_the_outermost_transaction_discards_the_memo(): void
    {
        $queries = $this->reuse()->within(function () {
            $this->allows();
            event(new TransactionCommitting(DB::connection()));

            return $this->queriesOf(fn () => $this->allows());
        });

        $this->assertGreaterThan(0, $queries);
    }

    public function test_a_nested_one_shares_the_outer_one_and_leaving_the_outer_one_forgets(): void
    {
        $afterInner = $this->reuse()->within(function () {
            $this->reuse()->within(fn () => $this->allows());

            return $this->queriesOf(fn () => $this->allows());
        });

        $this->assertSame(0, $afterInner);
        $this->assertGreaterThan(0, $this->queriesOf(fn () => $this->allows()));
    }

    public function test_an_exception_within_is_thrown_as_is_and_leaves_the_one(): void
    {
        $thrown = null;

        try {
            $this->reuse()->within(function () {
                $this->allows();

                throw new RuntimeException('読み取りの失敗');
            });
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertSame('読み取りの失敗', $thrown->getMessage());
        $this->assertGreaterThan(0, $this->queriesOf(fn () => $this->allows()));
    }

    /** 要求の寿命の区切りの後も、覚える側と捨てる側は同じ実体(書いた後の判定は判定し直す) */
    public function test_the_memo_stays_one_across_the_end_of_a_request_scope(): void
    {
        $memo = app(AuthorizationMemo::class);
        $authorization = app(Authorization::class);

        $results = $this->reuse()->within(function () use ($authorization) {
            $decide = fn () => $authorization->allowsUri($this->requester, $this->uri, 'read', self::day(self::DATE));
            $before = $decide();
            $this->resetRequestScope();
            $this->deny();

            return [$before, $decide()];
        });

        $this->assertSame($memo, app(AuthorizationMemo::class));
        $this->assertSame([true, false], $results);
    }

    /**
     * 自分に合うポリシーの無いリソースグループのリソースの URI。
     */
    private function unpermittedUri(): string
    {
        $group = AuthzResourceGroup::factory()->create();

        return AuthzResource::factory()
            ->create(['authz_resource_group_id' => $group->id, 'resource_type' => 'im-menu-group'])->uri;
    }

    /**
     * 自分と同じ対象者に入れた別のユーザー(read を許される)。
     */
    private function permittedOtherUser(): Requester
    {
        $other = $this->user();
        AuthzSubjectCondition::factory()
            ->create(['authz_subject_group_id' => $this->mine->id, 'user_id' => $other->id]);

        return new Requester($other->id, null);
    }

    private function requesterIn(int $departmentId): Requester
    {
        return new Requester($this->user->id, null, $departmentId);
    }
}
