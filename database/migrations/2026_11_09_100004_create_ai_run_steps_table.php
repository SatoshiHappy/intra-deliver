<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会話の型の実行のステップの行と、その送った文と応答の原文の表(docs の 設計/AI/実行の記録.md 2.3・2.2)。
 * ステップは会話の型の実行だけが持つので、種類の型を写した列と実行を複合の外部キーで ai_runs の (id, kind_type) に結ぶ。子どうしを同じ実行に縛るため、
 * 送り直した元のステップも (実行, ステップ) の複合の外部キーで指す。期限で消す原文は 1 対 1 の別の表に置き、行ごと消す(設計/AI/データと失敗と検査.md 6 節)。
 * 表と索引を足すだけで、ai_runs の今の列と制約は変えない(前の版のコードは新しい表を知らずに動く)。
 */
return new class extends Migration
{
    /** 回の答え・続き・要約・要約の予備(会話の用途で送る) */
    private const CONVERSATION_SEND_KINDS = "'turn', 'continuation', 'compaction', 'compaction_fallback'";

    /** ファイルを作る道具の中の呼び出し(中で呼んだ道具の行を持つ) */
    private const CREATE_FILE_SEND_KINDS = "'create_file', 'workspace_continuation'";

    /** 失敗の種類は実行と同じ一覧(後始末が実行の失敗の種類をそのまま写す) */
    private const ERROR_CODES = "'provider', 'timeout', 'incomplete', 'conversion', 'completion', 'worker_lost',"
        ." 'setting', 'confirmation'";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->unique(['id', 'kind_type']);
            $table->unique(['previous_run_id', 'id']);
        });
        self::createStepsTable();

        Schema::create('ai_run_step_texts', function (Blueprint $table) {
            $table->foreignId('ai_run_step_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('sent_text');
            $table->text('output_text')->nullable();
            $table->timestampTz('created_at');

            $table->index('created_at');
        });
    }

    /** ステップの行の表と検査制約 */
    private static function createStepsTable(): void
    {
        Schema::create('ai_run_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ai_run_id');
            $table->string('run_kind_type', 16);
            $table->integer('sequence');
            $table->string('purpose', 16);
            $table->string('send_kind', 32);
            $table->integer('retry_attempt')->nullable();
            $table->unsignedBigInteger('retried_from_step_id')->nullable();
            $table->unsignedBigInteger('ai_tool_call_id')->nullable();
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->decimal('reserved_cost_usd', 12, 6);
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('cache_read_input_tokens')->nullable();
            $table->integer('cache_write_input_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();
            $table->jsonb('pricing')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->decimal('code_execution_seconds', 10, 3)->nullable();
            $table->string('status', 16);
            $table->string('error_code', 32)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['ai_run_id', 'id']);
            $table->unique(['ai_run_id', 'sequence']);
            $table->index('started_at');
            $table->foreign(['ai_run_id', 'run_kind_type'])->references(['id', 'kind_type'])->on('ai_runs')
                ->cascadeOnDelete();
            // 同じ実行の中の参照は文の終わりで確かめる(実行の CASCADE が同じ文で両方を消す)
            $table->foreign(['ai_run_id', 'retried_from_step_id'])->references(['ai_run_id', 'id'])->on('ai_run_steps')
                ->noActionOnDelete();
        });

        foreach (self::stepChecks() as $name => $check) {
            DB::statement("ALTER TABLE ai_run_steps ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    /**
     * Reverse the migrations。ステップの行があると戻せない(原文の行はステップに縛られる。表を消すと、会話の型の実行の記録を消すことになる)。
     */
    public function down(): void
    {
        if (DB::table('ai_run_steps')->exists()) {
            throw new RuntimeException('ステップの行が残っているので戻せない(表を消すと、会話の型の実行の記録を消すことになる)');
        }

        Schema::drop('ai_run_step_texts');
        Schema::drop('ai_run_steps');
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropUnique(['previous_run_id', 'id']);
            $table->dropUnique(['id', 'kind_type']);
        });
    }

    /**
     * ステップの行の検査制約(名前 → 式)。使用量の組は音声の長さを見ない式(ステップは音声を持たない)。
     * 表の形の制約(用途と送信の種別・状態と列の有無)は、値の一覧の制約より名前の順で後に確かめられる名前にする。
     *
     * @return array<string, string>
     */
    private static function stepChecks(): array
    {
        $createFile = "purpose = 'create_file'";

        return [
            'ai_run_steps_run_kind_type_check' => "run_kind_type = 'conversation'",
            'ai_run_steps_sequence_check' => 'sequence >= 1',
            'ai_run_steps_purpose_check' => "purpose IN ('conversation', 'title', 'create_file')",
            'ai_run_steps_send_kind_check' => 'send_kind IN ('.self::CONVERSATION_SEND_KINDS.", 'title', "
                .self::CREATE_FILE_SEND_KINDS.')',
            'ai_run_steps_send_kind_purpose_check' => '(send_kind IN ('.self::CONVERSATION_SEND_KINDS.')'
                ." AND purpose = 'conversation' AND ai_tool_call_id IS NULL)"
                ." OR (send_kind = 'title' AND purpose = 'title' AND ai_tool_call_id IS NULL)"
                .' OR (send_kind IN ('.self::CREATE_FILE_SEND_KINDS.')'
                ." AND {$createFile} AND ai_tool_call_id IS NOT NULL)",
            'ai_run_steps_retry_check' => '(retry_attempt IS NULL) = (retried_from_step_id IS NULL)'
                .' AND retry_attempt >= 1',
            'ai_run_steps_reserved_cost_usd_check' => 'reserved_cost_usd >= 0',
            'ai_run_steps_tokens_check' => 'input_tokens >= 0 AND output_tokens >= 0 AND cache_read_input_tokens >= 0'
                .' AND cache_write_input_tokens >= 0 AND reasoning_tokens >= 0',
            'ai_run_steps_usage_check' => '(input_tokens IS NULL) = (output_tokens IS NULL)'
                .' AND (input_tokens IS NULL) = (pricing IS NULL) AND (input_tokens IS NULL) = (cost_usd IS NULL)',
            'ai_run_steps_usage_breakdown_check' => 'input_tokens IS NOT NULL'
                .' OR (cache_read_input_tokens IS NULL AND cache_write_input_tokens IS NULL'
                .' AND reasoning_tokens IS NULL)',
            'ai_run_steps_pricing_check' => "jsonb_typeof(pricing) = 'object'",
            'ai_run_steps_cost_usd_check' => 'cost_usd >= 0',
            'ai_run_steps_code_execution_seconds_check' => 'code_execution_seconds >= 0',
            'ai_run_steps_status_check' => "status IN ('running', 'succeeded', 'failed', 'stopped')",
            'ai_run_steps_error_code_check' => 'error_code IN ('.self::ERROR_CODES.')',
            'ai_run_steps_status_error_code_check' => "(status = 'failed') = (error_code IS NOT NULL)",
            'ai_run_steps_status_finished_at_check' => "(status = 'running') = (finished_at IS NULL)",
            'ai_run_steps_status_usage_check' => "(status <> 'running' OR input_tokens IS NULL)"
                ." AND (status <> 'succeeded' OR input_tokens IS NOT NULL)",
            'ai_run_steps_status_code_execution_seconds_check' => '(code_execution_seconds IS NOT NULL)'
                ." = ({$createFile} AND input_tokens IS NOT NULL)",
        ];
    }
};
