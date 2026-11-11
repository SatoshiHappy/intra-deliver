<?php

namespace Tests\Feature\Deploy;

use App\Queries\Ai\RunSpending;
use Tests\Support\TransactionalTestCase;

/**
 * 退避から繰り越しを戻す道具が、会話の型の実行をステップの行から数えること(docs の 設計/AI/実行と費用/接続先と費用.md 5.2 の「使った額」と
 * 「繰り越し」、戻し方 = 設計/公開の配備/配備と戻し方.md 6.7 の作り直しの 5)と、ステップの表の COPY の無い前の版の退避の扱い(道具の頭のコメントの
 * 代償)。足し込む SQL が毎日の初期化と同じ式の写しであることも確かめる。道具は {@see RestoresAiCarryovers}。
 */
final class RestoreAiCarryoversStepsScriptTest extends TransactionalTestCase
{
    use RestoresAiCarryovers;

    /**
     * ステップの行は、ステップの開始の時刻の東京の日(0:00 の前後)で、実行の区分に足し込む(実行を作った日でなく)。費用の分からないステップは
     * 見積もりで数え、待ちの実行のステップは数えない。文章の行と混ざる日はその和になる。
     */
    public function test_it_adds_the_steps_by_the_tokyo_day_of_their_start(): void
    {
        $dump = $this->writeDump(
            [
                self::PROMPT_ROW,
                self::CONVERSATION_ROW,
                "10\ttranscription\tfailed\t2026-10-16 10:00:00+00\t\\N\t\\N\tconversation",
                "11\tdefault\tqueued\t2026-10-17 10:00:00+00\t\\N\t\\N\tconversation",
            ],
            ["default\t2026-10-17\t3.000000"],
            steps: [
                "1\t9\t1\t2026-10-17 14:59:59+00\t0.250000\t1.000000",
                "2\t9\t2\t2026-10-17 15:00:00+00\t\\N\t0.500000",
                "3\t9\t3\t2026-10-18 14:59:59+00\t0.000000\t2.000000",
                "4\t10\t1\t2026-10-17 15:00:00+00\t\\N\t0.125000",
                "5\t11\t1\t2026-10-17 14:00:00+00\t\\N\t9.000000",
            ],
        );

        $result = $this->restore($dump);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame([
            'default 2026-10-17 4.250000',
            'default 2026-10-18 0.500000',
            'transcription 2026-10-18 0.125000',
        ], $this->carryovers());
    }

    /** 戻す先に無い列を持つステップの COPY も、残りの列で戻せる */
    public function test_it_restores_steps_with_columns_the_new_table_does_not_have(): void
    {
        $dump = $this->writeDump(
            [self::CONVERSATION_ROW],
            [],
            steps: [self::STEP_ROW."\told"],
            stepsHeader: 'COPY public.ai_run_steps (id, ai_run_id, sequence, started_at, cost_usd, reserved_cost_usd,'
                .' "legacy") FROM stdin;',
        );

        $result = $this->restore($dump);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(['default 2026-10-18 0.500000'], $this->carryovers());
    }

    /**
     * ステップの表の COPY の無い退避(ステップの表を足すマイグレーションの前の表から取った退避)は、docker を呼ばずに、前の版の作業木の道具で
     * 戻すよう告げて終了 1(道具の頭のコメントの代償)。
     */
    public function test_it_stops_with_the_way_out_when_the_dump_has_no_steps(): void
    {
        $dump = $this->writeDump([self::PROMPT_ROW], ["default\t2026-10-17\t3.000000"], stepsHeader: null);

        $result = $this->restore($dump);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('ai_run_steps の COPY の段がありません(前の版の退避です)', $result->stderr);
        $this->assertStringContainsString('前の版の作業木の scripts/deploy/restore-ai-carryovers で戻してください', $result->stderr);
        $this->assertStringContainsString('何も入れていません', $result->stderr);
    }

    /**
     * 足し込む SQL は、毎日の初期化が繰り越しを書くのと同じ式(RunSpending の 1 か所)を、道具の 2 つの見かけの表の名前で写したもの
     * (空白の並びはそろえて比べる)。
     */
    public function test_the_script_counts_with_the_same_expressions_as_the_daily_reset(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy/restore-ai-carryovers'));
        $expected = RunSpending::carryOverRowsSql('pg_temp.counted_runs', 'pg_temp.counted_steps');

        $this->assertStringContainsString(self::spaced($expected), self::spaced($script));
    }

    /** 空白の並び(改行と字下げを含む)を 1 つの空白にそろえる */
    private static function spaced(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }
}
