<?php

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 退避から AI の費用の繰り越しを戻す scripts/deploy/restore-ai-carryovers(docs の 設計/公開の配備/配備と戻し方.md 6.7 の作り直しの 5、数え方 =
 * docs の 設計/AI/実行と費用/接続先と費用.md 5.2)を、db のコンテナのコマンドをテスト DB に流す偽の docker で確かめる。退避は pg_dump の平文の形を
 * 写したファイル(ほかの表の COPY と psql のメタコマンドの行を含む)。道具は {@see RestoresAiCarryovers}。会話の型のステップの行から数えることと
 * 写しのテストは {@see RestoreAiCarryoversStepsScriptTest}。
 */
final class RestoreAiCarryoversScriptTest extends TransactionalTestCase
{
    use RestoresAiCarryovers;

    /**
     * 退避の繰り越しに、退避の実行の記録の使った額(費用が無ければ見積もり)を区分 × 東京の日(0:00 の前後)で足し込む。待ちの実行は数えない。
     */
    public function test_it_adds_the_spending_of_the_runs_in_the_dump_to_the_carryovers_in_the_dump(): void
    {
        $dump = $this->writeDump(
            [
                self::PROMPT_ROW,
                "2\tdefault\trunning\t2026-10-17 15:00:00+00\t\\N\t0.500000\tprompt",
                "3\tdefault\tqueued\t2026-10-17 15:00:00+00\t\\N\t9.000000\tprompt",
                "4\ttranscription\tfailed\t2026-10-17 15:00:00+00\t0.250000\t1.000000\ttranscription",
                "5\ttranscription\tfailed\t2026-10-17 15:00:01+00\t\\N\t0.125000\ttranscription",
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
        $dump = $this->writeDump([self::PROMPT_ROW], []);

        $result = $this->restore($dump);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame(['default 2026-10-01 2.000000'], $this->carryovers());
        $this->assertStringContainsString('何も入っていません', $result->stderr);
    }

    /** 戻す先に無い列(前の版の退避が持つ列)は読み捨てて、残りの列で戻せる */
    public function test_it_restores_from_a_dump_with_columns_the_new_tables_do_not_have(): void
    {
        $dump = $this->writeDump(
            [self::PROMPT_ROW."\told"],
            ["default\t2026-10-17\t3.000000\tx"],
            carryoversHeader: 'COPY public.ai_spending_carryovers (budget, spent_on, cost_usd, gone) FROM stdin;',
            runsHeader: 'COPY public.ai_runs (id, budget, status, created_at, cost_usd, reserved_cost_usd, kind_type,'
                .' "legacy") FROM stdin;',
        );

        $result = $this->restore($dump);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(['default 2026-10-17 4.000000'], $this->carryovers());
    }

    /**
     * 数える列(実行の id・区分・状態・作った時刻・費用・見積もり。ステップの実行・開始の時刻・費用・見積もり。繰り越しの区分・日・額)が欠けた退避は、
     * 何も入れずに終了 1。
     *
     * @return array<string, array{string, string, string}>
     */
    public static function dumpsMissingACountedColumn(): array
    {
        $runs = ['id, budget, status, created_at, cost_usd, reserved_cost_usd, kind_type', self::PROMPT_ROW];
        $steps = ['id, ai_run_id, sequence, started_at, cost_usd, reserved_cost_usd', self::STEP_ROW];
        $carryovers = ['budget, spent_on, cost_usd', "default\t2026-10-17\t3.000000"];
        $without = fn (array $block, string $column, int $position): array => [
            implode(', ', array_diff(explode(', ', $block[0]), [$column])),
            implode("\t", array_diff_key(explode("\t", $block[1]), [$position => 0])),
        ];

        return [
            'runs without id' => [...$without($runs, 'id', 0), 'runs'],
            'runs without budget' => [...$without($runs, 'budget', 1), 'runs'],
            'runs without status' => [...$without($runs, 'status', 2), 'runs'],
            'runs without created_at' => [...$without($runs, 'created_at', 3), 'runs'],
            'runs without cost_usd' => [...$without($runs, 'cost_usd', 4), 'runs'],
            'runs without reserved_cost_usd' => [...$without($runs, 'reserved_cost_usd', 5), 'runs'],
            'steps without ai_run_id' => [...$without($steps, 'ai_run_id', 1), 'steps'],
            'steps without started_at' => [...$without($steps, 'started_at', 3), 'steps'],
            'steps without cost_usd' => [...$without($steps, 'cost_usd', 4), 'steps'],
            'steps without reserved_cost_usd' => [...$without($steps, 'reserved_cost_usd', 5), 'steps'],
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
        $header = fn (string $name): string => "COPY public.{$name} ({$columns}) FROM stdin;";
        $runs = [self::PROMPT_ROW, self::CONVERSATION_ROW];
        $carryovers = ["default\t2026-10-17\t3.000000"];
        $dump = match ($table) {
            'runs' => $this->writeDump([$row], $carryovers, runsHeader: $header('ai_runs'), steps: [self::STEP_ROW]),
            'steps' => $this->writeDump($runs, $carryovers, steps: [$row], stepsHeader: $header('ai_run_steps')),
            default => $this->writeDump($runs, [$row], carryoversHeader: $header('ai_spending_carryovers')),
        };

        $result = $this->restore($dump);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->carryovers());
        $this->assertStringContainsString('何も入っていません', $result->stderr);
    }

    /**
     * 実行の型(kind_type)の無い退避(実行の型の列を足すマイグレーションの前の版の退避)は、docker を呼ばずに、前の版の作業木の道具で戻すよう
     * 告げて終了 1(道具の頭のコメントの代償)。
     */
    public function test_it_stops_with_the_way_out_when_the_runs_in_the_dump_have_no_kind_type(): void
    {
        $dump = $this->writeDump(
            ["1\tdefault\tsucceeded\t2026-10-17 14:59:59+00\t1.000000\t2.000000"],
            ["default\t2026-10-17\t3.000000"],
            runsHeader: 'COPY public.ai_runs (id, budget, status, created_at, cost_usd, reserved_cost_usd) FROM stdin;',
        );

        $result = $this->restore($dump);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('前の版の作業木の scripts/deploy/restore-ai-carryovers で戻してください', $result->stderr);
        $this->assertStringContainsString('何も入れていません', $result->stderr);
    }

    /** 途中の失敗(額が数でない)は全体が巻き戻り、何も入らない */
    public function test_a_failure_in_the_middle_adds_nothing(): void
    {
        $dump = $this->writeDump(
            [self::PROMPT_ROW],
            ["default\t2026-10-17\tnot-a-number"],
        );

        $result = $this->restore($dump);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->carryovers());
    }

    /**
     * 退避に 3 つの表の COPY の段が 1 つずつ揃っていない・閉じていないときは、docker を呼ばずに終了 1(ステップの表の段だけが無い退避は
     * {@see RestoreAiCarryoversStepsScriptTest} の前の版の退避)。
     *
     * @return array<string, array{string}>
     */
    public static function incompleteDumps(): array
    {
        $runs = self::RUNS_HEADER."\n".self::PROMPT_ROW."\n\\.\n";
        $steps = self::STEPS_HEADER."\n\\.\n";
        $carryovers = self::CARRYOVERS_HEADER."\n\\.\n";

        return [
            'no carryovers' => [$runs.$steps],
            'no runs' => [$steps.$carryovers],
            'runs twice' => [$runs.$runs.$steps.$carryovers],
            'steps twice' => [$runs.$steps.$steps.$carryovers],
            'unterminated' => [$runs.$steps.self::CARRYOVERS_HEADER."\ndefault\t2026-10-17\t3.000000\n"],
            'unterminated steps' => [$runs.self::STEPS_HEADER."\n".$carryovers],
            'insert statements' => ["INSERT INTO public.ai_runs VALUES (1);\n".$steps.$carryovers],
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

    /**
     * 会話の型の実行の行は数えない(会話の型はステップの行から数える = 同じ日にステップの行を足した場合は
     * {@see RestoreAiCarryoversStepsScriptTest}): ステップの無い会話の型の行だけの日は繰り越しが増えず(費用と見積もりの無い行でも止まらない)、
     * 文章の行と混ざる日は文章の行の分だけを足す。
     */
    public function test_it_leaves_out_the_runs_of_the_conversation_type(): void
    {
        $dump = $this->writeDump(
            [
                "1\tdefault\trunning\t2026-10-18 01:00:00+00\t\\N\t\\N\tconversation",
                "2\tdefault\tfailed\t2026-10-18 02:00:00+00\t\\N\t\\N\tconversation",
                "3\tdefault\tstopped\t2026-10-18 03:00:00+00\t\\N\t\\N\tconversation",
                "4\tdefault\tsucceeded\t2026-10-19 01:00:00+00\t0.500000\t1.000000\tprompt",
                "5\tdefault\tawaiting_confirmation\t2026-10-19 02:00:00+00\t\\N\t\\N\tconversation",
            ],
            ["default\t2026-10-18\t3.000000"],
        );

        $result = $this->restore($dump);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(['default 2026-10-18 3.000000', 'default 2026-10-19 0.500000'], $this->carryovers());
    }
}
