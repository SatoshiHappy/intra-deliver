<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI の実行に種類の型と会話の型の列を足す(docs の 設計/AI/実行の記録.md 2.1、状態 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.11)。
 * 種類の型は依頼の口が写し、検査制約は型ごとに持つ列と状態をこの値で見る。前からある行は音声の長さの有無で文章と文字起こしに分ける
 * (前の型ごとの列の組の制約が、音声の長さのある行 = 文字起こしを保証している)。
 * 会話の型が持たない列(接続先・モデル・見積もり・送ったデータを読んだレコード)を NULL 可にし(型は前のまま)、型ごとの列の組の制約で縛る。
 * 前の実行は会話の型の実行の中で一意(1 つの実行に続く実行は 1 つ = ADR 0040 の決定 14)。今の制約は名前を保って外して足し直す。
 * 状態の列は確認待ち(awaiting_confirmation)が入る長さに広げる。
 */
return new class extends Migration
{
    /** 会話の型だけが持つ列(ほかの型の行は NULL) */
    private const CONVERSATION_COLUMNS = [
        'agent_definition_id', 'previous_run_id', 'wants_title', 'answer',
        'stop_requested_at', 'stop_reason', 'stop_budget_period',
        'purposes', 'passed_tools', 'knowledge_modules', 'compacted', 'retry_wait_seconds', 'retry_attempt',
        'step_reserved_sum_usd', 'step_cost_sum_usd', 'step_usage_sum',
    ];

    /** 会話の型が持たない列(会話の型の行は NULL) */
    private const NOT_CONVERSATION_COLUMNS = [
        'input_upload_id', 'original_input_upload_id', 'audio_seconds', 'max_output_tokens',
        'provider', 'model', 'reserved_cost_usd',
        'record_id', 'original_record_id', 'record_module_code', 'source_record_ids',
        'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens',
        'pricing', 'cost_usd',
    ];

    /** 前の状態の一覧 */
    private const STATUSES_BEFORE = "'queued', 'running', 'succeeded', 'failed'";

    /** 前の失敗の種類の一覧 */
    private const ERROR_CODES_BEFORE = "'provider', 'timeout', 'incomplete', 'conversion', 'completion', 'worker_lost'";

    /** 会話の型だけの状態と失敗の種類 */
    private const CONVERSATION_STATUSES = "'awaiting_confirmation', 'stopped'";

    private const CONVERSATION_ERROR_CODES = "'setting', 'confirmation'";

    /** 足し直す制約の前の式(名前 → 式) */
    private const CHECKS_BEFORE = [
        'ai_runs_status_check' => 'status IN ('.self::STATUSES_BEFORE.')',
        'ai_runs_error_code_check' => 'error_code IN ('.self::ERROR_CODES_BEFORE.')',
        'ai_runs_status_started_at_check' => "(status <> 'queued' OR started_at IS NULL)"
            ." AND (status NOT IN ('running', 'succeeded') OR started_at IS NOT NULL)",
        'ai_runs_status_finished_at_check' => "(status IN ('succeeded', 'failed')) = (finished_at IS NOT NULL)",
        'ai_runs_status_cost_usd_check' => "status <> 'succeeded' OR cost_usd IS NOT NULL",
        'ai_runs_type_columns_check' => '(audio_seconds IS NOT NULL AND original_input_upload_id IS NOT NULL'
            .' AND max_output_tokens IS NULL)'
            .' OR (audio_seconds IS NULL AND original_input_upload_id IS NULL AND max_output_tokens IS NOT NULL)',
    ];

    /** このマイグレーションが足す制約の名前(down で外す) */
    private const ADDED_CHECKS = [
        'ai_runs_kind_type_check',
        'ai_runs_kind_type_status_check',
        'ai_runs_kind_type_error_code_check',
        'ai_runs_status_stop_reason_check',
        'ai_runs_stop_reason_check',
        'ai_runs_stop_budget_period_check',
        'ai_runs_answer_check',
        'ai_runs_answer_previous_run_id_check',
        'ai_runs_retry_check',
        'ai_runs_step_sums_check',
        'ai_runs_conversation_json_check',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('kind_type', 16)->nullable();
            $table->foreignId('agent_definition_id')->nullable()
                ->constrained('ai_agent_definitions')->restrictOnDelete();
            $table->foreignId('previous_run_id')->nullable()->unique()->constrained('ai_runs')->restrictOnDelete();
            $table->boolean('wants_title')->nullable();
            $table->string('answer', 16)->nullable();
            $table->timestampTz('stop_requested_at')->nullable();
            $table->string('stop_reason', 16)->nullable();
            $table->string('stop_budget_period', 8)->nullable();
            $table->jsonb('purposes')->nullable();
            $table->jsonb('passed_tools')->nullable();
            $table->jsonb('knowledge_modules')->nullable();
            $table->boolean('compacted')->nullable();
            $table->integer('retry_wait_seconds')->nullable();
            $table->integer('retry_attempt')->nullable();
            $table->decimal('step_reserved_sum_usd', 12, 6)->nullable();
            $table->decimal('step_cost_sum_usd', 12, 6)->nullable();
            $table->jsonb('step_usage_sum')->nullable();
        });

        $kindType = "CASE WHEN audio_seconds IS NULL THEN 'prompt' ELSE 'transcription' END";
        DB::table('ai_runs')->update(['kind_type' => DB::raw($kindType)]);

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('kind_type', 16)->nullable(false)->change();
            $table->string('status', 32)->change();
            $table->string('provider', 64)->nullable()->change();
            $table->string('model', 128)->nullable()->change();
            $table->decimal('reserved_cost_usd', 12, 6)->nullable()->change();
            $table->jsonb('source_record_ids')->nullable()->change();
        });

        foreach (self::checksAfter() as $name => $check) {
            $this->addCheck($name, $check);
        }
    }

    /**
     * Reverse the migrations。会話の型の行があると戻せない(会話の型の列を消し、接続先などを NOT NULL に戻すと、
     * 会話の型の実行の記録を消すことになる)。
     */
    public function down(): void
    {
        if (DB::table('ai_runs')->where('kind_type', 'conversation')->exists()) {
            throw new RuntimeException(
                '会話の型の実行の行が残っているので戻せない(接続先などを NOT NULL に戻すと、会話の型の実行の記録を消すことになる)',
            );
        }

        foreach ([...self::ADDED_CHECKS, ...array_keys(self::CHECKS_BEFORE)] as $name) {
            DB::statement("ALTER TABLE ai_runs DROP CONSTRAINT {$name}");
        }

        foreach (self::CHECKS_BEFORE as $name => $check) {
            $this->addCheck($name, $check);
        }

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('status', 16)->change();
            $table->string('provider', 64)->nullable(false)->change();
            $table->string('model', 128)->nullable(false)->change();
            $table->decimal('reserved_cost_usd', 12, 6)->nullable(false)->change();
            $table->jsonb('source_record_ids')->nullable(false)->change();
            $table->dropConstrainedForeignId('agent_definition_id');
            $table->dropUnique(['previous_run_id']);
            $table->dropConstrainedForeignId('previous_run_id');
            $plain = array_diff(self::CONVERSATION_COLUMNS, ['agent_definition_id', 'previous_run_id']);
            $table->dropColumn(['kind_type', ...$plain]);
        });
    }

    /**
     * 足した後の制約(名前 → 式)。前からある名前は外して足し直す。
     *
     * @return array<string, string>
     */
    private static function checksAfter(): array
    {
        $statuses = self::STATUSES_BEFORE.', '.self::CONVERSATION_STATUSES;
        $ended = "'succeeded', 'failed', ".self::CONVERSATION_STATUSES;
        $errorCodes = self::ERROR_CODES_BEFORE.', '.self::CONVERSATION_ERROR_CODES;
        $conversation = "kind_type = 'conversation'";

        return [
            'ai_runs_kind_type_check' => "kind_type IN ('prompt', 'transcription', 'conversation')",
            'ai_runs_type_columns_check' => self::typeColumnsCheck(),
            'ai_runs_status_check' => "status IN ({$statuses})",
            'ai_runs_kind_type_status_check' => "{$conversation} OR status NOT IN (".self::CONVERSATION_STATUSES.')',
            'ai_runs_error_code_check' => "error_code IN ({$errorCodes})",
            'ai_runs_kind_type_error_code_check' => "{$conversation}"
                .' OR error_code NOT IN ('.self::CONVERSATION_ERROR_CODES.')',
            'ai_runs_status_started_at_check' => "(status <> 'queued' OR started_at IS NULL)"
                ." AND (status NOT IN ('running', 'succeeded', 'awaiting_confirmation') OR started_at IS NOT NULL)",
            'ai_runs_status_finished_at_check' => "(status IN ({$ended})) = (finished_at IS NOT NULL)",
            'ai_runs_status_cost_usd_check' => "{$conversation} OR status <> 'succeeded' OR cost_usd IS NOT NULL",
            'ai_runs_status_stop_reason_check' => "(status = 'stopped') = (stop_reason IS NOT NULL)",
            'ai_runs_stop_reason_check' => "stop_reason IN ('user', 'time_limit', 'turn_limit', 'length', 'budget')",
            'ai_runs_stop_budget_period_check' => "(stop_reason IS NOT DISTINCT FROM 'budget')"
                ." = (stop_budget_period IS NOT NULL) AND stop_budget_period IN ('day', 'month', 'total')",
            'ai_runs_answer_check' => "answer IN ('approve', 'reject')",
            'ai_runs_answer_previous_run_id_check' => 'answer IS NULL'
                .' OR (previous_run_id IS NOT NULL AND wants_title = false)',
            'ai_runs_retry_check' => '(retry_wait_seconds IS NULL) = (retry_attempt IS NULL)'
                .' AND retry_wait_seconds >= 0 AND retry_attempt >= 1',
            'ai_runs_step_sums_check' => 'step_reserved_sum_usd >= 0 AND step_cost_sum_usd >= 0',
            'ai_runs_conversation_json_check' => "jsonb_typeof(purposes) = 'object'"
                ." AND jsonb_typeof(passed_tools) = 'array' AND jsonb_typeof(knowledge_modules) = 'array'"
                ." AND jsonb_typeof(step_usage_sum) = 'object'",
        ];
    }

    /**
     * 種類の型ごとの列の組。文章と文字起こしは前の組(音声の長さ・元の音声・出力の上限)に加えて接続先・モデル・見積もり・
     * 送ったデータを読んだレコードを持ち、会話の型だけの列を持たない。会話の型は定義と題を作るかを持ち、会話の型が持たない列を持たない。
     */
    private static function typeColumnsCheck(): string
    {
        $isNull = static fn (array $columns): string => implode(' AND ', array_map(
            static fn (string $column): string => "{$column} IS NULL",
            $columns,
        ));
        $common = 'provider IS NOT NULL AND model IS NOT NULL AND reserved_cost_usd IS NOT NULL'
            .' AND source_record_ids IS NOT NULL AND '.$isNull(self::CONVERSATION_COLUMNS);

        return "(kind_type = 'prompt' AND audio_seconds IS NULL AND original_input_upload_id IS NULL"
            ." AND max_output_tokens IS NOT NULL AND {$common})"
            ." OR (kind_type = 'transcription' AND audio_seconds IS NOT NULL AND original_input_upload_id IS NOT NULL"
            ." AND max_output_tokens IS NULL AND {$common})"
            ." OR (kind_type = 'conversation' AND agent_definition_id IS NOT NULL AND wants_title IS NOT NULL"
            .' AND '.$isNull(self::NOT_CONVERSATION_COLUMNS).')';
    }

    /** 制約を足す(同じ名前があれば外してから) */
    private function addCheck(string $name, string $check): void
    {
        DB::statement("ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT {$name} CHECK ({$check})");
    }
};
