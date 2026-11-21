<?php

namespace Tests\Feature\Migrations;

use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の型の実行の行の見積もりの和を消すマイグレーション(docs の 設計/AI/実行と費用/接続先と費用.md 5.3。ADR 0051 の決定 2): 上げると列が無く、
 * 種類の型ごとの列の組とステップの和の制約は残る(ステップの行の見積もりは残る)。戻すと列と制約が前の式で戻り、ステップの見積もりの和が入る。
 * DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class DropAiRunsStepReservedSumMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_11_21_100001_drop_ai_runs_step_reserved_sum.php';

    private const BEFORE_MIGRATION = '2026_11_09_100002_add_conversation_to_ai_runs_table.php';

    public function test_up_drops_the_run_sum_and_keeps_the_step_estimate_and_the_checks(): void
    {
        $this->assertFalse(Schema::hasColumn('ai_runs', 'step_reserved_sum_usd'));
        $this->assertTrue(Schema::hasColumn('ai_run_steps', 'reserved_cost_usd'));
        $this->assertSame(['ai_runs_step_sums_check', 'ai_runs_type_columns_check'], $this->runChecks());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ai_runs_type_columns_check');
        AiRun::factory()->conversation()->create(['provider' => 'anthropic']);
    }

    public function test_the_step_sums_check_still_rejects_a_negative_cost_sum(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ai_runs_step_sums_check');

        AiRun::factory()->conversation()->create(['step_cost_sum_usd' => '-0.000001']);
    }

    public function test_down_restores_the_columns_and_the_checks_of_the_migration_before(): void
    {
        $run = AiRun::factory()->conversation()->create(['step_cost_sum_usd' => '0.003000']);
        AiRunStep::factory()->succeeded('0.002000')
            ->create(['ai_run_id' => $run->id, 'reserved_cost_usd' => '0.010000']);
        AiRunStep::factory()->create(['ai_run_id' => $run->id, 'reserved_cost_usd' => '0.020000']);
        $unsummed = AiRun::factory()->conversation()->create();

        $this->migrator()->reset([$this->path(self::MIGRATION)]);

        $sums = DB::table('ai_runs')->whereIn('id', [$run->id, $unsummed->id])->orderBy('id')
            ->pluck('step_reserved_sum_usd')->all();
        $this->assertSame(['0.030000', null], $sums);
        $this->assertSame($this->beforeTypeColumnsCheck(), $this->definitionOf('ai_runs_type_columns_check'));
        $this->assertSame(
            'CHECK (((step_reserved_sum_usd >= (0)::numeric) AND (step_cost_sum_usd >= (0)::numeric)))',
            $this->definitionOf('ai_runs_step_sums_check'),
        );
    }

    public function test_up_after_down_drops_the_column_again(): void
    {
        $this->migrator()->reset([$this->path(self::MIGRATION)]);
        $this->migrator()->run([$this->path(self::MIGRATION)]);

        $this->assertFalse(Schema::hasColumn('ai_runs', 'step_reserved_sum_usd'));
        $this->assertSame(['ai_runs_step_sums_check', 'ai_runs_type_columns_check'], $this->runChecks());
    }

    /** 前のマイグレーションが組む種類の型ごとの列の組の式を、同じ表に別の名前で足して読んだ定義(PostgreSQL が整えた形で比べる) */
    private function beforeTypeColumnsCheck(): ?string
    {
        $before = require $this->path(self::BEFORE_MIGRATION);
        $expression = (new ReflectionMethod($before, 'typeColumnsCheck'))->invoke(null);
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT before_type_columns_check CHECK ({$expression}) NOT VALID");
        $definition = $this->definitionOf('before_type_columns_check');
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT before_type_columns_check');

        return $definition === null ? null : str_replace(' NOT VALID', '', $definition);
    }

    private function definitionOf(string $name): ?string
    {
        $row = DB::selectOne(
            "select pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'ai_runs'::regclass"
            .' and conname = ?',
            [$name],
        );

        return $row?->definition;
    }

    /**
     * このマイグレーションが外して足し直す実行の表の制約の名前。
     *
     * @return list<string>
     */
    private function runChecks(): array
    {
        /** @var list<string> $names */
        $names = DB::table('pg_constraint')->whereRaw("conrelid = 'ai_runs'::regclass")
            ->whereIn('conname', ['ai_runs_type_columns_check', 'ai_runs_step_sums_check'])
            ->orderBy('conname')->pluck('conname')->all();

        return $names;
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
