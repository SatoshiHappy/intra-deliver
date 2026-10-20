<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI の提案の表(docs の 設計/AI/README.md 2.2)。作成の案に要る列だけを作る(更新の案の 3 列と action の update は、
 * 更新の案を出すツールを作るときに足す)。採用で作ったレコードは SET NULL、元の ID を外部キーの無い original_created_record_id に持つ。
 * input はレコードの入力の封筒(values・details)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_run_id')->constrained()->cascadeOnDelete();
            $table->integer('seq');
            $table->string('record_module_code', 100);
            $table->string('action', 16);
            $table->jsonb('input');
            $table->text('note')->nullable();
            $table->string('status', 16);
            $table->foreignId('decided_by_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('created_record_id')->nullable()->index()->constrained('records')->nullOnDelete();
            $table->bigInteger('original_created_record_id')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['ai_run_id', 'seq']);
        });

        DB::statement('ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_seq_check CHECK (seq >= 1)');
        DB::statement("ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_record_module_code_check CHECK (record_module_code <> '')");
        DB::statement("ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_action_check CHECK (action IN ('create'))");
        DB::statement("ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_input_check CHECK (jsonb_typeof(input) = 'object')");
        DB::statement("ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_note_check CHECK (note <> '')");
        DB::statement("ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_status_check CHECK (status IN ('proposed', 'accepted', 'rejected'))");
        DB::statement("ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_status_decided_check CHECK ((status = 'proposed') = (decided_by_user_id IS NULL) AND (status = 'proposed') = (decided_at IS NULL))");
        DB::statement("ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_status_original_created_record_id_check CHECK ((status = 'accepted') = (original_created_record_id IS NOT NULL))");
        DB::statement('ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_created_record_id_original_created_record_id_check CHECK (created_record_id IS NULL OR created_record_id = original_created_record_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_proposals');
    }
};
