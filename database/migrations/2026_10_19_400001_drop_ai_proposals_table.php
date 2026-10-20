<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI の提案の表を消す(提案の仕組みは ADR 0019 で外すと決めた)。
 * down は作成のマイグレーション(2026_10_18_100003_create_ai_proposals_table)と同じ表と制約を作り直す(行は戻らない)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('ai_proposals');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
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

        $checks = [
            'seq_check' => 'seq >= 1',
            'record_module_code_check' => "record_module_code <> ''",
            'action_check' => "action IN ('create')",
            'input_check' => "jsonb_typeof(input) = 'object'",
            'note_check' => "note <> ''",
            'status_check' => "status IN ('proposed', 'accepted', 'rejected')",
            'status_decided_check' => "(status = 'proposed') = (decided_by_user_id IS NULL)"
                ." AND (status = 'proposed') = (decided_at IS NULL)",
            'status_original_created_record_id_check' => "(status = 'accepted')"
                .' = (original_created_record_id IS NOT NULL)',
            'created_record_id_original_created_record_id_check' => 'created_record_id IS NULL'
                .' OR created_record_id = original_created_record_id',
        ];

        foreach ($checks as $name => $condition) {
            DB::statement("ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_{$name} CHECK ({$condition})");
        }
    }
};
