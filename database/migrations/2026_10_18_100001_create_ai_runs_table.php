<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI の実行(LLM の呼び出し 1 回)の表(docs の 設計/AI/README.md 2.1)。記録の表で、業務レコードを消しても残る
 * (record_id は SET NULL、元の ID を外部キーの無い original_record_id と業務モジュールのコード record_module_code に持つ)。
 * 種類のコード(kind)の一覧は設定と種類の実装が持ち、DB に一覧の検査制約を置かない。
 * 使用量・単価・費用は分かったときに一緒に書く(分からない失敗は NULL のまま。上限の確認は見積もり reserved_cost_usd で数える = 5.2)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 64);
            $table->string('status', 16);
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('record_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->bigInteger('original_record_id')->nullable();
            $table->string('record_module_code', 100)->nullable();
            $table->jsonb('source_record_ids');
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->integer('max_output_tokens');
            $table->decimal('reserved_cost_usd', 12, 6);
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('cache_read_input_tokens')->nullable();
            $table->integer('cache_write_input_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();
            $table->jsonb('pricing')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->index('created_at');
            $table->index(['requester_user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_kind_check CHECK (kind <> '')");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'failed'))");
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_record_id_original_record_id_check CHECK (record_id IS NULL OR record_id = original_record_id)');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_original_record_id_record_module_code_check CHECK ((original_record_id IS NULL) = (record_module_code IS NULL))');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_source_record_ids_check CHECK (jsonb_typeof(source_record_ids) = 'array')");
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_max_output_tokens_check CHECK (max_output_tokens > 0)');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_reserved_cost_usd_check CHECK (reserved_cost_usd >= 0)');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_tokens_check CHECK (input_tokens >= 0 AND output_tokens >= 0 AND cache_read_input_tokens >= 0 AND cache_write_input_tokens >= 0 AND reasoning_tokens >= 0)');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_usage_check CHECK ((input_tokens IS NULL) = (output_tokens IS NULL) AND (input_tokens IS NULL) = (pricing IS NULL) AND (input_tokens IS NULL) = (cost_usd IS NULL))');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_usage_breakdown_check CHECK (input_tokens IS NOT NULL OR (cache_read_input_tokens IS NULL AND cache_write_input_tokens IS NULL AND reasoning_tokens IS NULL))');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_pricing_check CHECK (jsonb_typeof(pricing) = 'object')");
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_cost_usd_check CHECK (cost_usd >= 0)');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_cost_usd_check CHECK (status <> 'succeeded' OR cost_usd IS NOT NULL)");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_error_code_check CHECK (error_code IN ('provider', 'timeout', 'incomplete', 'completion', 'worker_lost'))");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_error_code_error_message_check CHECK ((status = 'failed') = (error_code IS NOT NULL) AND (status = 'failed') = (error_message IS NOT NULL))");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_started_at_check CHECK ((status <> 'queued' OR started_at IS NULL) AND (status NOT IN ('running', 'succeeded') OR started_at IS NOT NULL))");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_finished_at_check CHECK ((status IN ('succeeded', 'failed')) = (finished_at IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
