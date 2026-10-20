<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 確認(追記だけ。確認日時は created_at。docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.11 節)。ノードと履歴の行は
 * 同じ案件のもの、確認した人はそのノードの確認対象者(複合外部キー)。何度でも確認できるので一意を置かない。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_case_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_id')->index()->constrained()->cascadeOnDelete();
            $table->bigInteger('workflow_case_node_id');
            $table->bigInteger('workflow_case_history_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['workflow_case_id', 'workflow_case_node_id'])
                ->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->restrictOnDelete();
            $table->foreign(['workflow_case_id', 'workflow_case_history_id'])
                ->references(['workflow_case_id', 'id'])->on('workflow_case_histories')->restrictOnDelete();
            $table->foreign(['workflow_case_node_id', 'user_id'])->references(['workflow_case_node_id', 'user_id'])
                ->on('workflow_case_confirm_targets')->restrictOnDelete();
            $table->index(['workflow_case_node_id', 'user_id']);
            $table->index(['workflow_case_node_id', 'workflow_case_id']);
            $table->index(['workflow_case_history_id', 'workflow_case_id']);
            // 確認済の述語が引き、外部キー user_id の索引を兼ねる
            $table->index(['user_id', 'workflow_case_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_case_confirmations');
    }
};
