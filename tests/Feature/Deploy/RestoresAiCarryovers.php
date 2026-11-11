<?php

namespace Tests\Feature\Deploy;

use stdClass;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Support\RunsDeployScripts;

/**
 * 退避から AI の費用の繰り越しを戻す scripts/deploy/restore-ai-carryovers のテストが使う道具(db のコンテナのコマンドをテスト DB に流す偽の docker・
 * pg_dump の平文の形を写した退避・確定した繰り越しの行)。スクリプトが確定させた繰り越しの行は、テストの終わりにトランザクションの外で消す。
 */
trait RestoresAiCarryovers
{
    use RunsDeployScripts {
        setUp as setUpDeployScripts;
    }

    private const RUNS_HEADER = 'COPY public.ai_runs (id, budget, status, created_at, cost_usd, reserved_cost_usd,'
        .' kind_type) FROM stdin;';

    /** 文章の型の実行の行(区分・状態・作った時刻・費用・見積もり。待ちでなく、費用の分かった行) */
    private const PROMPT_ROW = "1\tdefault\tsucceeded\t2026-10-17 14:59:59+00\t1.000000\t2.000000\tprompt";

    /** 会話の型の実行の行(費用と見積もりを持たない。数える額はステップの行が持つ) */
    private const CONVERSATION_ROW = "9\tdefault\tsucceeded\t2026-10-17 01:00:00+00\t\\N\t\\N\tconversation";

    private const STEPS_HEADER = 'COPY public.ai_run_steps (id, ai_run_id, sequence, started_at, cost_usd,'
        .' reserved_cost_usd) FROM stdin;';

    /** 会話の型の実行 9 のステップの行(実行・番号・開始の時刻・費用・見積もり。費用の分からない行) */
    private const STEP_ROW = "1\t9\t1\t2026-10-17 15:00:00+00\t\\N\t0.500000";

    private const CARRYOVERS_HEADER = 'COPY public.ai_spending_carryovers (budget, spent_on, cost_usd) FROM stdin;';

    protected function setUp(): void
    {
        $this->setUpDeployScripts();

        $this->installDockerRunningDbOnTestDatabase();
        $this->assertSame([], $this->carryovers(), 'テスト DB の繰り越しの表は空から始める');
        $this->cleanUpCommitted('DELETE FROM ai_spending_carryovers');
    }

    private function restore(string $dump): ProcessResult
    {
        $arguments = [$this->composeEnv(), $dump];

        return $this->runScript('restore-ai-carryovers', $arguments, $this->testDatabaseEnvironment());
    }

    /**
     * pg_dump の平文の形の退避を書く(前後にほかの表の COPY と psql のメタコマンド・SET の行を置く)。見出しに null を渡すと、その表の段を書かない。
     *
     * @param  list<string>  $runs  ai_runs の COPY の行
     * @param  list<string>  $carryovers  ai_spending_carryovers の COPY の行
     * @param  list<string>  $steps  ai_run_steps の COPY の行
     */
    private function writeDump(
        array $runs,
        array $carryovers,
        string $carryoversHeader = self::CARRYOVERS_HEADER,
        string $runsHeader = self::RUNS_HEADER,
        array $steps = [],
        ?string $stepsHeader = self::STEPS_HEADER,
    ): string {
        $lines = [
            '--', '-- PostgreSQL database dump', '--', '\\restrict abc', "SET client_encoding = 'UTF8';",
            'COPY public.users (id, name) FROM stdin;', "1\tx", '\\.',
            $runsHeader, ...$runs, '\\.',
            ...($stepsHeader === null ? [] : [$stepsHeader, ...$steps, '\\.']),
            'COPY public.ai_run_step_texts (ai_run_step_id, sent_text) FROM stdin;', "1\tx", '\\.',
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
