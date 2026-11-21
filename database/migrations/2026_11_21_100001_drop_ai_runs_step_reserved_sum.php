<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会話の型の実行の行の見積もりの和を消す。会話の型の費用は比べに見積もりを足さず(docs の 設計/AI/実行と費用/接続先と費用.md 5.3。
 * ADR 0051 の決定 2)、見積もりはステップの行に残して終わって費用の分からないステップを数えるのにだけ使うので、表示だけの和は使い道が無い。
 * 実行の行の種類の型ごとの列の組の制約とステップの和の制約は、列を消すと PostgreSQL が制約ごと外すので、外してから消した列の無い式で
 * 足し直す。down は列を戻し、ステップの行の見積もりの和を入れ直す。
 */
return new class extends Migration
{
    /**
     * 会話の型だけが持つ列(ほかの型の行は NULL)。$withReserved = 消す前の列を含めるか
     *
     * @return list<string>
     */
    private static function conversationColumns(bool $withReserved): array
    {
        return [
            'agent_definition_id', 'previous_run_id', 'wants_title', 'answer',
            'stop_requested_at', 'stop_reason', 'stop_budget_period',
            'purposes', 'passed_tools', 'knowledge_modules', 'compacted', 'retry_wait_seconds', 'retry_attempt',
            ...($withReserved ? ['step_reserved_sum_usd'] : []), 'step_cost_sum_usd', 'step_usage_sum',
        ];
    }

    /** 会話の型が持たない列(会話の型の行は NULL) */
    private const NOT_CONVERSATION_COLUMNS = [
        'input_upload_id', 'original_input_upload_id', 'audio_seconds', 'max_output_tokens',
        'provider', 'model', 'reserved_cost_usd',
        'record_id', 'original_record_id', 'record_module_code', 'source_record_ids',
        'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens',
        'pricing', 'cost_usd',
    ];

    public function up(): void
    {
        $this->dropChecks();

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('step_reserved_sum_usd');
        });

        $this->addChecks(false);
    }

    public function down(): void
    {
        $this->dropChecks();

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->decimal('step_reserved_sum_usd', 12, 6)->nullable();
        });
        DB::table('ai_runs')->where('kind_type', 'conversation')->whereNotNull('step_cost_sum_usd')->update([
            'step_reserved_sum_usd' => DB::raw(
                '(SELECT coalesce(sum(reserved_cost_usd), 0) FROM ai_run_steps'
                .' WHERE ai_run_steps.ai_run_id = ai_runs.id)',
            ),
        ]);

        $this->addChecks(true);
    }

    private function dropChecks(): void
    {
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_type_columns_check');
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_step_sums_check');
    }

    private function addChecks(bool $withReserved): void
    {
        $sums = $withReserved ? 'step_reserved_sum_usd >= 0 AND step_cost_sum_usd >= 0' : 'step_cost_sum_usd >= 0';

        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_type_columns_check CHECK ('
            .self::typeColumnsCheck($withReserved).')');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_step_sums_check CHECK ({$sums})");
    }

    /** 種類の型ごとの列の組(式の形は 2026_11_09_100002 と同じ。消した列のほかは変えない) */
    private static function typeColumnsCheck(bool $withReserved): string
    {
        $isNull = static fn (array $columns): string => implode(' AND ', array_map(
            static fn (string $column): string => "{$column} IS NULL",
            $columns,
        ));
        $common = 'provider IS NOT NULL AND model IS NOT NULL AND reserved_cost_usd IS NOT NULL'
            .' AND source_record_ids IS NOT NULL AND '.$isNull(self::conversationColumns($withReserved));

        return "(kind_type = 'prompt' AND audio_seconds IS NULL AND original_input_upload_id IS NULL"
            ." AND max_output_tokens IS NOT NULL AND {$common})"
            ." OR (kind_type = 'transcription' AND audio_seconds IS NOT NULL AND original_input_upload_id IS NOT NULL"
            ." AND max_output_tokens IS NULL AND {$common})"
            ." OR (kind_type = 'conversation' AND agent_definition_id IS NOT NULL AND wants_title IS NOT NULL"
            .' AND '.$isNull(self::NOT_CONVERSATION_COLUMNS).')';
    }
};
