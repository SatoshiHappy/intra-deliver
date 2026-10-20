<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 写した差戻しできる先(案件のノードの部品。docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.4)。差戻し元と先は同じ案件のノード(複合外部キー)。
 * 形は差戻しできる先(2026_10_16_100004)と同じで、案件への外部キーとその索引を持つ(直列化と検査.md 5 節の手当て 4)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_case_send_backs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_id')->index()->constrained()->cascadeOnDelete();
            $table->bigInteger('workflow_case_node_id');
            $table->bigInteger('target_workflow_case_node_id');
            $table->timestampsTz();

            $table->unique(['workflow_case_node_id', 'target_workflow_case_node_id']);
            $table->foreign(['workflow_case_id', 'workflow_case_node_id'])->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->cascadeOnDelete();
            $table->foreign(['workflow_case_id', 'target_workflow_case_node_id'])->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->restrictOnDelete();
            $table->index(['workflow_case_node_id', 'workflow_case_id']);
            $table->index(['target_workflow_case_node_id', 'workflow_case_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_case_send_backs');
    }
};
