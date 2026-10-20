<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 履歴の人(振替先と、処理対象者の変更・再展開の後の処理対象者。案件の部品。追記だけ。docs の
 * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.9 節)。履歴の行は同じ案件のもの(複合外部キー)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_case_history_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_id')->index()->constrained()->cascadeOnDelete();
            $table->bigInteger('workflow_case_history_id');
            $table->integer('sequence');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['workflow_case_history_id', 'sequence']);
            // 同じ行に同じ人を重ねない(外部キー user_id の索引を兼ねる)
            $table->unique(['user_id', 'workflow_case_history_id']);
            $table->foreign(['workflow_case_id', 'workflow_case_history_id'])
                ->references(['workflow_case_id', 'id'])->on('workflow_case_histories')->restrictOnDelete();
            $table->index(['workflow_case_history_id', 'workflow_case_id']);
        });

        DB::statement(
            'ALTER TABLE workflow_case_history_users ADD CONSTRAINT workflow_case_history_users_sequence_check '
            .'CHECK (sequence >= 1)',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_case_history_users');
    }
};
