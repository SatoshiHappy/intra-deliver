<?php

namespace Tests\Feature\Deploy;

use Tests\Support\RunsDeployScripts;
use Tests\Support\TransactionalTestCase;

/**
 * 毎日の初期化の様子を見る scripts/deploy/reset-status(docs の 設計/公開の配備/配備と戻し方.md 6.3 の 5 の結果の表・6.7)を、db のコンテナの
 * コマンドをテスト DB に流す偽の docker で確かめる。鎖錠と記録の行は、テストのトランザクションの外の接続で置く(スクリプトの psql から見えるように)。
 * 道具は {@see RunsDeployScripts}。
 */
final class ResetStatusScriptTest extends TransactionalTestCase
{
    use RunsDeployScripts;

    public function test_it_shows_the_session_holding_the_lock_and_the_recent_runs_in_the_words_of_the_table(): void
    {
        $this->installDockerRunningDbOnTestDatabase();
        $committed = $this->committedConnection();
        $this->cleanUpCommitted('DELETE FROM reset_runs');
        $open = $this->insertRun('NULL, NULL, NULL');
        $succeeded = $this->insertRun("now(), 'succeeded', now()");
        $failedAfterReload = $this->insertRun("now(), 'failed', now()");
        $failedBeforeReload = $this->insertRun("NULL, 'failed', now()");
        $committed->select('SELECT pg_advisory_lock(?)', [config('reset.lock_key')]);
        $holder = (int) $committed->selectOne('SELECT pg_backend_pid() AS pid')->pid;

        $result = $this->runScript('reset-status', [$this->composeEnv()], $this->testDatabaseEnvironment());

        $this->assertSame(0, $result->exitCode, $result->describe());
        [$locks, $runs] = $this->sections($result->stdout);
        $this->assertMatchesRegularExpression("/^\\s*{$holder}\\s*\\|/m", $locks);
        $this->assertSame(
            [
                [$failedBeforeReload, '失敗で閉じた', '無い'],
                [$failedAfterReload, '失敗で閉じた', 'ある'],
                [$succeeded, '成功で閉じた', 'ある'],
                [$open, '閉じていない', '無い'],
            ],
            $this->runRows($runs),
        );
    }

    public function test_it_shows_no_session_when_no_run_holds_the_lock(): void
    {
        $this->installDockerRunningDbOnTestDatabase();

        $result = $this->runScript('reset-status', [$this->composeEnv()], $this->testDatabaseEnvironment());

        $this->assertSame(0, $result->exitCode, $result->describe());
        [$locks, $runs] = $this->sections($result->stdout);
        $this->assertStringContainsString('(0 ', $locks);
        $this->assertSame([], $this->runRows($runs));
    }

    /** 問い合わせは db のコンテナで、アプリ用ロールとアプリの DB(コンテナの環境変数)に流す(SQL は引数。改行を含むので記録は複数の行になる) */
    public function test_it_queries_inside_the_db_container_as_the_app_role(): void
    {
        $result = $this->runScript('reset-status', [$this->composeEnv()]);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringStartsWith(
            self::PROJECT_ARGUMENTS." -f {$this->work}/compose.publish.yml --env-file {$this->composeEnv()}"
            .' exec -T db sh -c exec psql -X -q --no-password --username="$APP_DB_USER" --dbname="$APP_DB_NAME" "$@"'
            .' psql -v ON_ERROR_STOP=1 ',
            implode("\n", $this->calls()),
        );
    }

    public function test_it_ends_with_one_when_the_query_fails(): void
    {
        $this->installDocker([['exec', 2, 'psql: error']]);

        $result = $this->runScript('reset-status', [$this->composeEnv()]);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('dc ps', $result->stderr);
    }

    public function test_it_stops_without_calling_docker_when_the_arguments_are_wrong(): void
    {
        $result = $this->runScript('reset-status', []);

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
    }

    /** 鎖錠の鍵は初期化が使う鍵と同じ */
    public function test_the_lock_key_is_the_one_the_reset_takes(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy/reset-status'));

        $key = preg_match('/^lock_key=(\d+)$/m', $script, $matches) === 1 ? (int) $matches[1] : null;

        $this->assertSame(config('reset.lock_key'), $key);
    }

    /** reset_runs に 1 行を確定で入れる($rest = reloaded_at・result・finished_at の SQL)。行の番号を返す */
    private function insertRun(string $rest): int
    {
        return (int) $this->committedConnection()->selectOne(
            'INSERT INTO reset_runs (reset_on, trigger, started_at, reloaded_at, result, finished_at)'
            ." VALUES ('2026-10-18', 'manual', now(), {$rest}) RETURNING id",
        )->id;
    }

    /**
     * 出力の 2 つの節(鎖錠を持つセッション・記録の行)。
     *
     * @return array{string, string}
     */
    private function sections(string $stdout): array
    {
        $parts = preg_split('/^==> .*$/m', $stdout);

        $this->assertIsArray($parts);
        $this->assertCount(3, $parts, $stdout);

        return [$parts[1], $parts[2]];
    }

    /**
     * 記録の節の行(行の番号・記録の行・入れ直しの印)。
     *
     * @return list<array{int, string, string}>
     */
    private function runRows(string $section): array
    {
        $rows = [];

        foreach (explode("\n", $section) as $line) {
            $cells = array_map('trim', explode('|', $line));

            if (count($cells) === 7 && ctype_digit($cells[0])) {
                $rows[] = [(int) $cells[0], $cells[4], $cells[5]];
            }
        }

        return $rows;
    }
}
