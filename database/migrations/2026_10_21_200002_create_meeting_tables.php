<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会議の書き起こし・発言の行・会議と AI の実行の対応の 3 表(docs の 設計/業務ツール/会議/README.md の 2 節、
 * 処理の状態と再作成が対応を読む形 = 設計/業務ツール/会議/処理.md の 3.7・3.8)。どれも会議のレコードの付属物で、レコードを消すと一緒に消え
 * (CASCADE)、AI の実行の記録は消さない(RESTRICT = 設計/業務ツール/共通データ.md の「専用の表」)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->createTranscripts();
        $this->createTranscriptSegments();
        $this->createMeetingRuns();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_runs');
        Schema::dropIfExists('transcript_segments');
        Schema::dropIfExists('transcripts');
    }

    /**
     * 書き起こし(会議 1 つに 1 行)。音声のときだけ文字起こしの実行と区切った数を持つ。
     */
    private function createTranscripts(): void
    {
        Schema::create('transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('file_name', 255)->nullable();
            $table->string('language', 8);
            $table->foreignId('ai_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('chunk_count')->default(1);
            $table->timestampsTz();

            $table->index('ai_run_id');
        });

        DB::statement('ALTER TABLE transcripts ADD CONSTRAINT transcripts_source_check'
            ." CHECK (source IN ('audio', 'text_file', 'paste'))");
        DB::statement('ALTER TABLE transcripts ADD CONSTRAINT transcripts_source_file_name_check'
            ." CHECK ((source = 'paste') = (file_name IS NULL))");
        DB::statement('ALTER TABLE transcripts ADD CONSTRAINT transcripts_source_ai_run_id_check'
            ." CHECK ((source = 'audio') = (ai_run_id IS NOT NULL))");
        DB::statement('ALTER TABLE transcripts ADD CONSTRAINT transcripts_chunk_count_source_check'
            ." CHECK (chunk_count >= 1 AND (source = 'audio' OR chunk_count = 1))");
    }

    /**
     * 発言の行(書き起こしの中の並び 1 から)。
     */
    private function createTranscriptSegments(): void
    {
        Schema::create('transcript_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcript_id')->constrained()->cascadeOnDelete();
            $table->integer('seq');
            $table->string('speaker_label', 100)->nullable();
            $table->decimal('started_at_sec', 10, 3)->nullable();
            $table->decimal('ended_at_sec', 10, 3)->nullable();
            $table->text('text');
            $table->timestampsTz();

            $table->unique(['transcript_id', 'seq']);
        });

        DB::statement('ALTER TABLE transcript_segments ADD CONSTRAINT transcript_segments_seq_check CHECK (seq >= 1)');
        DB::statement('ALTER TABLE transcript_segments ADD CONSTRAINT transcript_segments_speaker_label_check'
            ." CHECK (speaker_label IS NULL OR speaker_label <> '')");
        DB::statement('ALTER TABLE transcript_segments ADD CONSTRAINT transcript_segments_started_at_sec_check'
            .' CHECK (started_at_sec IS NULL OR started_at_sec >= 0)');
        DB::statement('ALTER TABLE transcript_segments ADD CONSTRAINT transcript_segments_ended_at_sec_check'
            .' CHECK (ended_at_sec IS NULL OR ended_at_sec >= 0)');
        DB::statement('ALTER TABLE transcript_segments'
            .' ADD CONSTRAINT transcript_segments_started_at_sec_ended_at_sec_check'
            .' CHECK (started_at_sec IS NULL OR ended_at_sec IS NULL OR started_at_sec <= ended_at_sec)');
        DB::statement('ALTER TABLE transcript_segments ADD CONSTRAINT transcript_segments_text_check'
            ." CHECK (text <> '')");
    }

    /**
     * 会議と AI の実行の対応(会議ごとに直列に書くので、会議の最後の対応 = id が最大の行)。実行の無い行 = 断られた依頼で、
     * 理由と依頼した人を持つ(実行のある行の依頼した人は実行の記録が正本)。
     */
    private function createMeetingRuns(): void
    {
        Schema::create('meeting_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_run_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('stage', 16);
            $table->string('origin', 16);
            $table->boolean('writes_title');
            $table->string('declined_code', 16)->nullable();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['record_id', 'id']);
            $table->index('requester_user_id');
        });

        DB::statement('ALTER TABLE meeting_runs ADD CONSTRAINT meeting_runs_stage_check'
            ." CHECK (stage IN ('transcription', 'minutes'))");
        DB::statement('ALTER TABLE meeting_runs ADD CONSTRAINT meeting_runs_origin_check'
            ." CHECK (origin IN ('create', 'regenerate'))");
        DB::statement('ALTER TABLE meeting_runs ADD CONSTRAINT meeting_runs_origin_writes_title_check'
            ." CHECK (origin = 'create' OR NOT writes_title)");
        DB::statement('ALTER TABLE meeting_runs ADD CONSTRAINT meeting_runs_declined_code_check'
            ." CHECK (declined_code IN ('budget', 'too_long'))");
        DB::statement('ALTER TABLE meeting_runs ADD CONSTRAINT meeting_runs_ai_run_id_declined_code_check'
            .' CHECK ((ai_run_id IS NULL) = (declined_code IS NOT NULL))');
        DB::statement('ALTER TABLE meeting_runs ADD CONSTRAINT meeting_runs_declined_code_stage_check'
            ." CHECK (declined_code IS NULL OR stage = 'minutes')");
        DB::statement('ALTER TABLE meeting_runs ADD CONSTRAINT meeting_runs_ai_run_id_requester_user_id_check'
            .' CHECK ((ai_run_id IS NULL) = (requester_user_id IS NOT NULL))');
    }
};
