<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI の実行に文字起こしの音声と長さを足す(docs の 設計/AI/README.md 2.1)。音声の実体の行を消すと指す先を NULL にし、元の ID を残す
 * (record_id と original_record_id と同じ形)。種類の型の列は持たず、音声の長さ・元の音声・出力の上限の組で文章と文字起こしを分ける。
 * 文字起こしの費用は呼んだ区切りの長さで数えるので、使用量のトークンが分からなくても単価と費用を持てる(設計/AI/実行と費用.md 5.1)。
 */
return new class extends Migration
{
    private const USAGE_BEFORE = '(input_tokens IS NULL) = (output_tokens IS NULL)'
        .' AND (input_tokens IS NULL) = (pricing IS NULL)'
        .' AND (input_tokens IS NULL) = (cost_usd IS NULL)';

    /** 文字起こしの行(音声の長さがある)だけ、トークンが分からなくても単価と費用を持てる。トークンがあれば費用もある */
    private const USAGE_AFTER = '(input_tokens IS NULL) = (output_tokens IS NULL)'
        .' AND (pricing IS NULL) = (cost_usd IS NULL)'
        .' AND (input_tokens IS NULL OR pricing IS NOT NULL)'
        .' AND (audio_seconds IS NOT NULL OR (input_tokens IS NULL) = (pricing IS NULL))';

    private const ERROR_CODES_BEFORE = "'provider', 'timeout', 'incomplete', 'completion', 'worker_lost'";

    private const ERROR_CODES_AFTER = "'provider', 'timeout', 'incomplete', 'conversion', 'completion', 'worker_lost'";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->foreignId('input_upload_id')->nullable()->index()->constrained('uploads')->nullOnDelete();
            $table->bigInteger('original_input_upload_id')->nullable();
            $table->decimal('audio_seconds', 10, 3)->nullable();
            $table->integer('max_output_tokens')->nullable()->change();
        });

        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_input_upload_id_original_input_upload_id_check'
            .' CHECK (input_upload_id IS NULL OR input_upload_id = original_input_upload_id)');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_audio_seconds_check CHECK (audio_seconds > 0)');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_type_columns_check CHECK ('
            .'(audio_seconds IS NOT NULL AND original_input_upload_id IS NOT NULL AND max_output_tokens IS NULL)'
            .' OR (audio_seconds IS NULL AND original_input_upload_id IS NULL AND max_output_tokens IS NOT NULL))');
        $this->replaceChecks(self::USAGE_AFTER, self::ERROR_CODES_AFTER);
    }

    /**
     * Reverse the migrations。文字起こしの行があると戻せない(出力の上限を NOT NULL に戻すには、その行を消すことになる)。
     */
    public function down(): void
    {
        if (DB::table('ai_runs')->whereNotNull('audio_seconds')->exists()) {
            throw new RuntimeException(
                '文字起こしの実行の行が残っているので戻せない(出力の上限を NOT NULL に戻すと、文字起こしの実行の記録を消すことになる)',
            );
        }

        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_type_columns_check');
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_audio_seconds_check');
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_input_upload_id_original_input_upload_id_check');
        $this->replaceChecks(self::USAGE_BEFORE, self::ERROR_CODES_BEFORE);

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->integer('max_output_tokens')->nullable(false)->change();
            $table->dropConstrainedForeignId('input_upload_id');
            $table->dropColumn(['original_input_upload_id', 'audio_seconds']);
        });
    }

    /** 使用量と失敗の種類の制約を、今の名前のまま外して足し直す */
    private function replaceChecks(string $usage, string $errorCodes): void
    {
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_usage_check');
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_usage_check CHECK ({$usage})");
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_error_code_check');
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_error_code_check'
            ." CHECK (error_code IN ({$errorCodes}))");
    }
};
