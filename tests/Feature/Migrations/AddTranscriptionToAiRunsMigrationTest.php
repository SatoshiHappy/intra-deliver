<?php

namespace Tests\Feature\Migrations;

use App\Models\Master\User;
use App\Models\Upload\Upload;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 実行に文字起こしの音声と長さを足すマイグレーション(docs の 設計/AI/README.md 2.1): 前からある文章の行はそのまま通る。
 * down は文字起こしの行があれば理由つきで止まり(行を消さない)、無ければ列と制約を戻す(出力の上限は NOT NULL、失敗の種類から conversion を外す)。
 * DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AddTranscriptionToAiRunsMigrationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const MIGRATION = '2026_10_22_100001_add_transcription_to_ai_runs_table.php';

    private const ADDED_CONSTRAINTS = [
        'ai_runs_audio_seconds_check',
        'ai_runs_input_upload_id_foreign',
        'ai_runs_input_upload_id_original_input_upload_id_check',
        'ai_runs_type_columns_check',
    ];

    public function test_the_prompt_runs_from_before_pass_the_new_constraints(): void
    {
        $this->migrator()->reset([$this->path()]);
        $userId = User::factory()->create()->id;
        $this->insertRun($userId, ['input_tokens' => 1, 'output_tokens' => 1, 'pricing' => '{}', 'cost_usd' => '0']);

        $this->migrator()->run([$this->path()]);

        $row = (array) DB::table('ai_runs')->where('requester_user_id', $userId)
            ->first(['max_output_tokens', 'audio_seconds', 'input_upload_id', 'original_input_upload_id']);
        $this->assertSame([100, null, null, null], array_values($row));
        $this->assertSame(self::ADDED_CONSTRAINTS, $this->constraintNames(self::ADDED_CONSTRAINTS));
    }

    public function test_down_stops_with_a_reason_and_keeps_the_rows_while_a_transcription_run_remains(): void
    {
        $uploadId = Upload::factory()->create()->id;
        $userId = User::factory()->create()->id;
        $this->insertRun($userId, [
            'max_output_tokens' => null,
            'input_upload_id' => $uploadId,
            'original_input_upload_id' => $uploadId,
            'audio_seconds' => '60.000',
        ]);

        try {
            $this->migrator()->reset([$this->path()]);
            $this->fail('文字起こしの行があるのに、down が止まらなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('文字起こしの実行の行が残っている', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('ai_runs', 'audio_seconds'));
        $this->assertSame(1, DB::table('ai_runs')->where('requester_user_id', $userId)->count());
    }

    public function test_down_restores_the_columns_and_the_constraints(): void
    {
        $this->migrator()->reset([$this->path()]);

        $this->assertFalse(Schema::hasColumn('ai_runs', 'input_upload_id'));
        $this->assertFalse(Schema::hasColumn('ai_runs', 'original_input_upload_id'));
        $this->assertFalse(Schema::hasColumn('ai_runs', 'audio_seconds'));
        $this->assertSame([], $this->constraintNames(self::ADDED_CONSTRAINTS));
        $userId = User::factory()->create()->id;
        $noLimit = ['max_output_tokens' => null];
        $this->assertRejectedAsNull('ai_runs', 'max_output_tokens', fn () => $this->insertRun($userId, $noLimit));
        $pricedWithoutTokens = ['pricing' => '{}', 'cost_usd' => '0'];
        $this->assertRejectedBy('ai_runs_usage_check', fn () => $this->insertRun($userId, $pricedWithoutTokens));
        $failed = ['status' => 'failed', 'finished_at' => now(), 'error_message' => 'x', 'error_code' => 'conversion'];
        $this->assertRejectedBy('ai_runs_error_code_check', fn () => $this->insertRun($userId, $failed));
    }

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function path(): string
    {
        return database_path('migrations/'.self::MIGRATION);
    }

    /**
     * 表 ai_runs にある制約のうち、渡した名前のもの(名前の順)。
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function constraintNames(array $names): array
    {
        $rows = DB::select(
            "select conname from pg_constraint where conrelid = 'ai_runs'::regclass and conname = any(?)"
                .' order by conname',
            ['{'.implode(',', $names).'}'],
        );

        return array_column($rows, 'conname');
    }

    /**
     * 実行の行を表に直に足す(列の足し引きの前後で書けるように、必要な列だけ)。
     *
     * @param  array<string, mixed>  $extra
     */
    private function insertRun(int $userId, array $extra = []): void
    {
        DB::table('ai_runs')->insert([
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
        ]);
    }
}
