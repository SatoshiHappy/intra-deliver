<?php

namespace Tests\Feature\Migrations;

use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * ステップと道具の行の表を足す 2 つのマイグレーション(docs の 設計/AI/実行の記録.md 2.2〜2.4): 上げると 4 つの表と ai_runs の 2 つの一意の索引ができ、
 * 戻すと消える。足した表に行があると戻すが理由つきで止まり、行を消さない。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class CreateConversationStepTablesMigrationTest extends TransactionalTestCase
{
    private const STEPS_MIGRATION = '2026_11_09_100004_create_ai_run_steps_table.php';

    private const TOOL_CALLS_MIGRATION = '2026_11_09_100005_create_ai_tool_calls_table.php';

    private const TABLES = ['ai_run_steps', 'ai_run_step_texts', 'ai_tool_calls', 'ai_tool_call_texts'];

    private const RUN_INDEXES = ['ai_runs_id_kind_type_unique', 'ai_runs_previous_run_id_id_unique'];

    public function test_up_adds_the_four_tables_and_the_two_indexes_and_down_removes_them(): void
    {
        $this->assertSame([true, true, true, true], $this->tablesExist());
        $this->assertSame(self::RUN_INDEXES, $this->runIndexes());

        $this->reset();

        $this->assertSame([false, false, false, false], $this->tablesExist());
        $this->assertSame([], $this->runIndexes());

        $this->migrator()->run([$this->path(self::STEPS_MIGRATION), $this->path(self::TOOL_CALLS_MIGRATION)]);

        $this->assertSame([true, true, true, true], $this->tablesExist());
        $this->assertSame(self::RUN_INDEXES, $this->runIndexes());
    }

    public function test_down_stops_with_a_reason_and_keeps_the_rows_while_a_tool_call_remains(): void
    {
        $call = AiToolCall::factory()->create();

        $this->assertDownStops(self::TOOL_CALLS_MIGRATION, '道具の呼び出しの行が残っている');

        $this->assertSame(1, DB::table('ai_tool_calls')->where('id', $call->id)->count());
    }

    public function test_down_stops_with_a_reason_and_keeps_the_rows_while_a_step_remains(): void
    {
        $step = AiRunStep::factory()->create();
        $this->migrator()->reset([$this->path(self::TOOL_CALLS_MIGRATION)]);

        $this->assertDownStops(self::STEPS_MIGRATION, 'ステップの行が残っている');

        $this->assertSame(1, DB::table('ai_run_steps')->where('id', $step->id)->count());
        $this->assertSame(self::RUN_INDEXES, $this->runIndexes());
    }

    private function assertDownStops(string $migration, string $reason): void
    {
        try {
            $this->migrator()->reset([$this->path($migration)]);
            $this->fail('足した表に行があるのに、down が止まらなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }

    /**
     * 4 つの表があるか。
     *
     * @return list<bool>
     */
    private function tablesExist(): array
    {
        return array_map(fn (string $table): bool => Schema::hasTable($table), self::TABLES);
    }

    /**
     * ai_runs の足した一意の索引の名前(名前の順)。
     *
     * @return list<string>
     */
    private function runIndexes(): array
    {
        $names = array_column(Schema::getIndexes('ai_runs'), 'name');
        $added = array_values(array_intersect($names, self::RUN_INDEXES));
        sort($added);

        return $added;
    }

    /** 2 つのマイグレーションを戻す(後のマイグレーションから順に) */
    private function reset(): void
    {
        $this->migrator()->reset([$this->path(self::STEPS_MIGRATION), $this->path(self::TOOL_CALLS_MIGRATION)]);
    }

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function path(string $migration): string
    {
        return database_path('migrations/'.$migration);
    }
}
