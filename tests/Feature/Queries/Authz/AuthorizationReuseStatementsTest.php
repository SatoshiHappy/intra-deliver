<?php

namespace Tests\Feature\Queries\Authz;

use App\Queries\Authz\Api\AuthorizationReuse;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 判定の使い回し {@see AuthorizationReuse} の、DB に送った文の見分け(docs の 設計/共通マスタ/ロールと認可/認可の確認/判定の使い回し.md 3.4 の捨てる場面)。
 */
final class AuthorizationReuseStatementsTest extends TransactionalTestCase
{
    use ArrangesAuthorizationReuse;

    /**
     * 捨てる文(表の下の捨てる場面)。どれもまとまりの中で送ると、次の判定は判定し直す。
     *
     * @return array<string, array{string}>
     */
    public static function discardingStatements(): array
    {
        return [
            '書き込みを含む WITH' => [
                'WITH changed AS (UPDATE authz_policies SET effect = effect WHERE false RETURNING id)'
                    .' SELECT count(*) FROM changed',
            ],
            '鍵を取る SELECT(FOR UPDATE)' => ['SELECT id FROM authz_policies WHERE false FOR UPDATE'],
            '鍵を取る SELECT(FOR SHARE)' => ['SELECT id FROM authz_policies WHERE false FOR SHARE'],
            '表を作る SELECT … INTO' => ['SELECT id INTO TEMPORARY TABLE authz_reuse_copy FROM authz_policies WHERE false'],
            '勧告ロック(try)' => ['SELECT pg_try_advisory_xact_lock(4100000099)'],
            '勧告ロック(トランザクション)' => ['SELECT pg_advisory_xact_lock(4100000099)'],
            '勧告ロック(共有)' => ['SELECT pg_advisory_xact_lock_shared(4100000099)'],
            '勧告ロック(セッション。同じ文で放す)' => ['SELECT pg_advisory_lock(4100000099), pg_advisory_unlock(4100000099)'],
            'SELECT でも WITH でもない文' => ['SET LOCAL statement_timeout = 0'],
        ];
    }

    /**
     * 見分けに迷う読み取り(判定の使い回し.md 3.4 の「見分けに迷う文は捨てる側」。文字列のリテラルを見分けないので、文字列の値に書き込みの語や
     * 勧告ロックの語を含む読み取りも捨てる)。
     *
     * @return array<string, array{string}>
     */
    public static function hardToTellStatements(): array
    {
        return [
            '文字列の値に書き込みの語を含む読み取り' => ["SELECT count(*) FROM authz_policies WHERE action = 'update'"],
            '文字列の値に勧告ロックの語を含む読み取り' => ["SELECT count(*) FROM pg_locks WHERE locktype = 'advisory'"],
        ];
    }

    /**
     * 捨てない読み取り(語の境界で見るので列の名前は語に当たらない。括弧で始まる UNION も読み取り)。
     *
     * @return array<string, array{string}>
     */
    public static function readingStatements(): array
    {
        return [
            '列の名前に語を含む' => ['SELECT count(*) FROM authz_policies WHERE updated_at IS NOT NULL OR created_at IS NULL'],
            '括弧で始まる UNION' => ['(SELECT 1) UNION (SELECT 2)'],
            '再帰の WITH' => [
                'WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 3) SELECT count(*) FROM n',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeAllowedUri();
    }

    #[DataProvider('discardingStatements')]
    public function test_a_statement_that_is_not_a_read_within_discards_the_memo(string $sql): void
    {
        $this->assertGreaterThan(0, $this->queriesAfterSending(fn () => DB::statement($sql)));
    }

    #[DataProvider('hardToTellStatements')]
    public function test_a_statement_hard_to_tell_from_a_write_discards_the_memo(string $sql): void
    {
        $this->assertGreaterThan(0, $this->queriesAfterSending(fn () => DB::select($sql)));
    }

    #[DataProvider('readingStatements')]
    public function test_a_read_within_keeps_the_memo(string $sql): void
    {
        $this->assertSame(0, $this->queriesAfterSending(fn () => DB::select($sql)));
    }

    /**
     * まとまりの中で 1 回判定し、文を送った後の同じ判定の問い合わせの数(0 = 覚えた結果を返した)。
     *
     * @param  Closure(): mixed  $send
     */
    private function queriesAfterSending(Closure $send): int
    {
        return $this->reuse()->within(function () use ($send) {
            $this->allows();
            $send();

            return $this->queriesOf(fn () => $this->allows());
        });
    }
}
