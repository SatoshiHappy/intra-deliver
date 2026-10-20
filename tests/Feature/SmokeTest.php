<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * Feature suite の smoke(#1)。
 *
 * HTTP が通ること、接続が Postgres のテスト DB であること、テストが基底の TX(レベル 1)の中で走ること(テストの終わりの巻き戻しは RefreshDatabase が行う)と、
 * 同じプロセスで先に走ったテストが行を残していないことと、テスト DB 以外の初期化を止める保護が働くことを確かめる。
 * ConfigCacheGuardTest が設定キャッシュ時の停止を確かめる子プロセスで、DB を使うテストとして名指しで走らせる(ファイルを動かす・消すときはそちらも直す)。
 * 流用元 = chatpinet_2 の同名のテスト(OpenAPI の文書のルートの確認は公開 API の段で足す。users 表が無いので cache 表で見る)。
 */
final class SmokeTest extends TransactionalTestCase
{
    public function test_health_route_responds_ok(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_default_connection_is_postgres_test_database(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertMatchesRegularExpression(
            $this->acceptedDatabaseNamePattern(config('guardrails.test_db_suffix')),
            $this->connectedDatabaseName(),
        );
    }

    public function test_runs_inside_the_base_transaction(): void
    {
        $this->assertSame(1, DB::transactionLevel());
    }

    /**
     * 並列の実行ではプロセスごとに DB が別なので、同じプロセスで先に走ったテストだけを見る。
     */
    public function test_no_rows_are_left_by_earlier_tests(): void
    {
        $this->assertDatabaseCount('cache', 0);
    }

    public function test_database_guard_rejects_a_database_without_the_test_suffix(): void
    {
        config(['guardrails.test_db_suffix' => '_not_this_database']);

        $this->expectException(RuntimeException::class);

        $this->ensureTestDatabase();
    }
}
