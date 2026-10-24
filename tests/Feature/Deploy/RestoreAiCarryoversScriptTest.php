<?php

namespace Tests\Feature\Deploy;

use App\Enums\Ai\AiRunStatus;
use App\Queries\Ai\RunSpending;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Support\RunsDeployScripts;
use Tests\Support\TransactionalTestCase;

/**
 * 退避から AI の費用の繰り越しを戻す scripts/deploy/restore-ai-carryovers(docs の 設計/公開の配備/配備と戻し方.md 6.7 の作り直しの 5、数え方 =
 * docs の 設計/AI/実行と費用.md 5.2)を、db のコンテナのコマンドをテスト DB に流す偽の docker で確かめる。退避は pg_dump の平文の形を写した
 * ファイル(ほかの表の COPY と psql のメタコマンドの行を含む)。スクリプトが確定させた繰り越しの行は、テストの終わりにトランザクションの外で消す。
 * 道具は {@see RunsDeployScripts}。
 */
final class RestoreAiCarryoversScriptTest extends TransactionalTestCase
{
    use RunsDeployScripts {
        setUp as setUpDeployScripts;
    }

    private const RUNS_HEADER = 'COPY public.ai_runs (id, budget, status, created_at, cost_usd, reserved_cost_usd)'
        .' FROM stdin;';

    private const CARRYOVERS_HEADER = 'COPY public.ai_spending_carryovers (budget, spent_on, cost_usd) FROM stdin;';

    protected function setUp(): void
    {
        $this->setUpDeployScripts();

        $this->installDockerRunningDbOnTestDatabase();
        $this->assertSame([], $this->carryovers(), 'テスト DB の繰り越しの表は空から始める');
        $this->cleanUpCommitted('DELETE FROM ai_spending_carryovers');
    }

    /**
     * 退避の繰り越しに、退避の実行の記録の使った額(費用が無ければ見積もり)を区分 × 東京の日(0:00 の前後)で足し込む。待ちの実行は数えない。
     */
    public function test_it_adds_the_spending_of_the_runs_in_the_dump_to_the_carryovers_in_the_dump(): void
    {
        $dump = $this->writeDump(
            [
                "1\tdefault\tsucceeded\t2026-10-17 14:59:59+00\t1.000000\t2.000000",
                "2\tdefault\trunning\t2026-10-17 15:00:00+00\t\\N\t0.500000",
                "3\tdefault\tqueued\t2026-10-17 15:00:00+00\t\\N\t9.000000",
                "4\ttranscription\tfailed\t2026-10-17 15:00:00+00\t0.250000\t1.000000",
                "5\ttranscription\tfailed\t2026-10-17 15:00:01+00\t\\N\t0.125000",
            ],
            ["default\t2026-10-17\t3.000000", "default\t2026-10-10\t1.000000"],
        );

        $result = $this->restore($dump);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame([
            'default 2026-10-10 1.000000',
            'default 2026-10-17 4.000000',
            'default 2026-10-18 0.500000',
            'transcription 2026-10-18 0.375000',
        ], $this->carryovers());
        $this->assertStringContainsString('==> 戻した繰り越し', $result->stdout);
    }

    /** 新しい DB の繰り越しの表に行が既にあれば、何も入れずに終了 1(2 回流しても額が倍にならない) */
    public function test_it_adds_nothing_when_the_carryovers_table_already_has_rows(): void
    {
        $this->committedConnection()->insert(
            "INSERT INTO ai_spending_carryovers (budget, spent_on, cost_usd) VALUES ('default', '2026-10-01', 2)",
        );
        $dump = $this->writeDump(["1\tdefault\tsucceeded\t2026-10-17 14:59:59+00\t1.000000\t2.000000"], []);

        $result = $this->restore($dump);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame(['default 2026-10-01 2.000000'], $this->carryovers());
        $this->assertStringContainsString('何も入っていません', $result->stderr);
    }

    /** 戻す先に無い列(前の版の退避が持つ列)は読み捨てて、残りの列で戻せる */
    public function test_it_restores_from_a_dump_with_columns_the_new_tables_do_not_have(): void
    {
        $dump = $this->writeDump(
            ["1\tdefault\tsucceeded\t2026-10-17 14:59:59+00\t1.000000\t2.000000\told"],
            ["default\t2026-10-17\t3.000000\tx"],
            carryoversHeader: 'COPY public.ai_spending_carryovers (budget, spent_on, cost_usd, gone) FROM stdin;',
            runsHeader: 'COPY public.ai_runs (id, budget, status, created_at, cost_usd, reserved_cost_usd, "legacy")'
                .' FROM stdin;',
        );

        $result = $this->restore($dump);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(['default 2026-10-17 4.000000'], $this->carryovers());
    }

    /**
     * 数える列(区分・状態・作った時刻・費用・見積もり。繰り越しの区分・日・額)が欠けた退避は、何も入れずに終了 1。
     *
     * @return array<string, array{string, string}>
     */
    public static function dumpsMissingACountedColumn(): array
    {
        $runs = [
            'id, budget, status, created_at, cost_usd, reserved_cost_usd',
            "1\tdefault\tsucceeded\t2026-10-17 14:59:59+00\t1.000000\t2.000000",
        ];
        $carryovers = ['budget, spent_on, cost_usd', "default\t2026-10-17\t3.000000"];
        $without = fn (array $block, string $column, int $position): array => [
            implode(', ', array_diff(explode(', ', $block[0]), [$column])),
            implode("\t", array_diff_key(explode("\t", $block[1]), [$position => 0])),
        ];

        return [
            'runs without budget' => [...$without($runs, 'budget', 1), 'runs'],
            'runs without status' => [...$without($runs, 'status', 2), 'runs'],
            'runs without created_at' => [...$without($runs, 'created_at', 3), 'runs'],
            'runs without cost_usd' => [...$without($runs, 'cost_usd', 4), 'runs'],
            'runs without reserved_cost_usd' => [...$without($runs, 'reserved_cost_usd', 5), 'runs'],
            'carryovers without budget' => [...$without($carryovers, 'budget', 0), 'carryovers'],
            'carryovers without spent_on' => [...$without($carryovers, 'spent_on', 1), 'carryovers'],
            'carryovers without cost_usd' => [...$without($carryovers, 'cost_usd', 2), 'carryovers'],
        ];
    }

    #[DataProvider('dumpsMissingACountedColumn')]
    public function test_it_adds_nothing_when_a_counted_column_is_missing_from_the_dump(
        string $columns,
        string $row,
        string $table,
    ): void {
        $runs = ["1\tdefault\tsucceeded\t2026-10-17 14:59:59+00\t1.000000\t2.000000"];
        $carryovers = ["default\t2026-10-17\t3.000000"];
        $dump = $table === 'runs'
            ? $this->writeDump([$row], $carryovers, runsHeader: "COPY public.ai_runs ({$columns}) FROM stdin;")
            : $this->writeDump(
                $runs,
                [$row],
                carryoversHeader: "COPY public.ai_spending_carryovers ({$columns}) FROM stdin;",
            );

        $result = $this->restore($dump);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->carryovers());
        $this->assertStringContainsString('何も入っていません', $result->stderr);
    }

    /** 途中の失敗(額が数でない)は全体が巻き戻り、何も入らない */
    public function test_a_failure_in_the_middle_adds_nothing(): void
    {
        $dump = $this->writeDump(
            ["1\tdefault\tsucceeded\t2026-10-17 14:59:59+00\t1.000000\t2.000000"],
            ["default\t2026-10-17\tnot-a-number"],
        );

        $result = $this->restore($dump);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->carryovers());
    }

    /**
     * 退避に 2 つの表の COPY の段が 1 つずつ揃っていない・閉じていないときは、docker を呼ばずに終了 1。
     *
     * @return array<string, array{string}>
     */
    public static function incompleteDumps(): array
    {
        $runs = self::RUNS_HEADER."\n1\tdefault\tsucceeded\t2026-10-17 14:59:59+00\t1.000000\t2.000000\n\\.\n";
        $carryovers = self::CARRYOVERS_HEADER."\n\\.\n";

        return [
            'no carryovers' => [$runs],
            'no runs' => [$carryovers],
            'runs twice' => [$runs.$runs.$carryovers],
            'unterminated' => [$runs.self::CARRYOVERS_HEADER."\ndefault\t2026-10-17\t3.000000\n"],
            'insert statements' => ["INSERT INTO public.ai_runs VALUES (1);\n".$carryovers],
        ];
    }

    #[DataProvider('incompleteDumps')]
    public function test_it_stops_without_calling_docker_when_the_dump_is_incomplete(string $content): void
    {
        file_put_contents($this->tools.'/dump.sql', $content);

        $result = $this->restore($this->tools.'/dump.sql');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
    }

    public function test_it_stops_without_calling_docker_when_the_arguments_are_wrong(): void
    {
        $missingDump = $this->restore($this->tools.'/missing.sql');
        $noDump = $this->runScript('restore-ai-carryovers', [$this->composeEnv()]);

        $this->assertSame(2, $missingDump->exitCode, $missingDump->describe());
        $this->assertSame(2, $noDump->exitCode, $noDump->describe());
        $this->assertSame([], $this->calls());
    }

    /** 使った額・日・待ちを外すことは、毎日の初期化が繰り越しを書くのと同じ式(RunSpending の 1 か所)の写し */
    public function test_the_script_counts_with_the_same_expressions_as_the_daily_reset(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy/restore-ai-carryovers'));

        $this->assertStringContainsString(
            'SELECT budget, '.RunSpending::runDaySql().', '.RunSpending::RUN_COST_SQL,
            $script,
        );
        $this->assertStringContainsString("WHERE status <> '".AiRunStatus::Queued->value."'", $script);
    }

    private function restore(string $dump): ProcessResult
    {
        $arguments = [$this->composeEnv(), $dump];

        return $this->runScript('restore-ai-carryovers', $arguments, $this->testDatabaseEnvironment());
    }

    /**
     * pg_dump の平文の形の退避を書く(前後にほかの表の COPY と psql のメタコマンド・SET の行を置く)。
     *
     * @param  list<string>  $runs  ai_runs の COPY の行
     * @param  list<string>  $carryovers  ai_spending_carryovers の COPY の行
     */
    private function writeDump(
        array $runs,
        array $carryovers,
        string $carryoversHeader = self::CARRYOVERS_HEADER,
        string $runsHeader = self::RUNS_HEADER,
    ): string {
        $lines = [
            '--', '-- PostgreSQL database dump', '--', '\\restrict abc', "SET client_encoding = 'UTF8';",
            'COPY public.users (id, name) FROM stdin;', "1\tx", '\\.',
            $runsHeader, ...$runs, '\\.',
            'COPY public.ai_run_texts (id, ai_run_id) FROM stdin;', "1\t1", '\\.',
            $carryoversHeader, ...$carryovers, '\\.',
            '\\unrestrict abc',
        ];
        $path = $this->tools.'/dump.sql';
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    /**
     * テスト DB の繰り越しの行(確定したもの。区分・日・額)。
     *
     * @return list<string>
     */
    private function carryovers(): array
    {
        $rows = $this->committedConnection()->select(
            "SELECT concat_ws(' ', budget, spent_on, cost_usd) AS line FROM ai_spending_carryovers"
            .' ORDER BY budget, spent_on',
        );

        return array_values(array_map(fn (stdClass $row): string => (string) $row->line, $rows));
    }
}
