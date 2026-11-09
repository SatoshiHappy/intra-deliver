<?php

namespace Tests\Feature\Migrations;

use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Models\Upload\Upload;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 実行に種類の型と会話の型の列を足すマイグレーション(docs の 設計/AI/実行の記録.md 2.1): 前からある文章と文字起こしの行は、音声の長さの有無で
 * prompt・transcription になる。down は会話の型の行があれば理由つきで止まり(行を消さない)、無ければ列と制約を戻す(接続先などを NOT NULL に戻し、
 * 状態と失敗の種類から会話の型の値を外し、型ごとの列の組の制約を前の式に戻す)。原文の表の会話の型の分のマイグレーションも同じ形で戻す。
 * DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AddConversationToAiRunsMigrationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const MIGRATION = '2026_11_09_100002_add_conversation_to_ai_runs_table.php';

    private const TEXTS_MIGRATION = '2026_11_09_100003_add_conversation_to_ai_run_texts_table.php';

    public function test_the_prompt_and_transcription_runs_from_before_get_their_kind_type(): void
    {
        $this->reset();
        $userId = User::factory()->create()->id;
        $uploadId = Upload::factory()->create()->id;
        $this->insertRun($userId, ['kind' => 'meeting.minutes']);
        $this->insertRun($userId, [
            'kind' => 'meeting.transcription',
            'max_output_tokens' => null,
            'input_upload_id' => $uploadId,
            'original_input_upload_id' => $uploadId,
            'audio_seconds' => '60.000',
        ]);

        $this->migrator()->run([$this->path(self::MIGRATION), $this->path(self::TEXTS_MIGRATION)]);

        $types = DB::table('ai_runs')->where('requester_user_id', $userId)->orderBy('id')->pluck('kind_type')->all();
        $this->assertSame(['prompt', 'transcription'], $types);
        $this->assertRejectedAsNull('ai_runs', 'kind_type', fn () => $this->insertRun($userId));
    }

    public function test_down_stops_with_a_reason_and_keeps_the_rows_while_a_conversation_run_remains(): void
    {
        $run = AiRun::factory()->conversation()->create();

        try {
            $this->migrator()->reset([$this->path(self::MIGRATION)]);
            $this->fail('会話の型の行があるのに、down が止まらなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('会話の型の実行の行が残っている', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('ai_runs', 'kind_type'));
        $this->assertSame(1, DB::table('ai_runs')->where('id', $run->id)->count());
    }

    public function test_down_of_the_texts_stops_while_a_conversation_text_remains(): void
    {
        $runId = AiRun::factory()->conversation()->create()->id;
        $text = ['ai_run_id' => $runId, 'system_text' => null, 'user_text' => '文', 'created_at' => now()];
        DB::table('ai_run_texts')->insert($text);

        try {
            $this->migrator()->reset([$this->path(self::TEXTS_MIGRATION)]);
            $this->fail('会話の型の原文の行があるのに、down が止まらなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('会話の型の実行の原文の行が残っている', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('ai_run_texts', 'partial_text'));
    }

    public function test_down_restores_the_columns_and_the_constraints(): void
    {
        $this->reset();

        foreach (['kind_type', 'agent_definition_id', 'previous_run_id', 'stop_reason', 'step_usage_sum'] as $column) {
            $this->assertFalse(Schema::hasColumn('ai_runs', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('ai_run_texts', 'partial_text'));
        $userId = User::factory()->create()->id;

        foreach (['provider', 'model', 'reserved_cost_usd', 'source_record_ids'] as $column) {
            $this->assertRejectedAsNull('ai_runs', $column, fn () => $this->insertRun($userId, [$column => null]));
        }

        $finished = ['started_at' => now(), 'finished_at' => now()];
        $stopped = ['status' => 'stopped', ...$finished];
        $this->assertRejectedBy('ai_runs_status_check', fn () => $this->insertRun($userId, $stopped));
        $failed = ['status' => 'failed', ...$finished, 'error_message' => 'x', 'error_code' => 'setting'];
        $this->assertRejectedBy('ai_runs_error_code_check', fn () => $this->insertRun($userId, $failed));
        $succeeded = ['status' => 'succeeded', ...$finished];
        $this->assertRejectedBy('ai_runs_status_cost_usd_check', fn () => $this->insertRun($userId, $succeeded));
        $this->assertSame(
            'CHECK ((((audio_seconds IS NOT NULL) AND (original_input_upload_id IS NOT NULL)'
            .' AND (max_output_tokens IS NULL)) OR ((audio_seconds IS NULL)'
            .' AND (original_input_upload_id IS NULL) AND (max_output_tokens IS NOT NULL))))',
            $this->constraintDefinition('ai_runs_type_columns_check'),
        );
        $added = 'select count(*) from pg_constraint'
            ." where conname like 'ai_runs_kind_type%' or conname like 'ai_runs_stop%'";
        $this->assertSame(0, (int) DB::scalar($added));
        $runId = $this->insertedRunId($userId);
        $text = ['ai_run_id' => $runId, 'system_text' => 's', 'user_text' => null, 'created_at' => now()];
        $this->assertRejectedAsNull('ai_run_texts', 'user_text', fn () => DB::table('ai_run_texts')->insert($text));
    }

    /** 制約の式(PostgreSQL が整えた形) */
    private function constraintDefinition(string $name): string
    {
        return (string) DB::scalar('select pg_get_constraintdef(oid) from pg_constraint where conname = ?', [$name]);
    }

    /** 原文の表と実行の表の会話の型の分を戻す(後のマイグレーションから順に) */
    private function reset(): void
    {
        $this->migrator()->reset([$this->path(self::MIGRATION), $this->path(self::TEXTS_MIGRATION)]);
    }

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function path(string $migration): string
    {
        return database_path('migrations/'.$migration);
    }

    /**
     * 実行の行を表に直に足す(列の足し引きの前後で書けるように、必要な列だけ)。
     *
     * @param  array<string, mixed>  $extra
     */
    private function insertRun(int $userId, array $extra = []): void
    {
        DB::table('ai_runs')->insert($this->runRow($userId, $extra));
    }

    /** 文章の待ちの実行の行を足して id を返す */
    private function insertedRunId(int $userId): int
    {
        return (int) DB::table('ai_runs')->insertGetId($this->runRow($userId));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function runRow(int $userId, array $extra = []): array
    {
        return [
            'kind' => 'meeting.minutes',
            'status' => 'queued',
            'requester_user_id' => $userId,
            'source_record_ids' => '[]',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'max_output_tokens' => 100,
            'reserved_cost_usd' => '0.010000',
            'created_at' => now(),
            ...$extra,
        ];
    }
}
