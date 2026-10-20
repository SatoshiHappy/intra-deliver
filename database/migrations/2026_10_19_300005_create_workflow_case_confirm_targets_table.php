<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 確認対象者(確認が初めて開いた時に、写した確認する人を申請基準日で展開した人。案件のノードの部品。docs の
 * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.10 節)。ノードは同じ案件のもの(複合外部キー)。
 * 一意 (workflow_case_node_id, user_id) は確認(2026_10_19_300006)の複合外部キーの参照先を兼ねる。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_case_confirm_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_id')->index()->constrained()->cascadeOnDelete();
            $table->bigInteger('workflow_case_node_id');
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['workflow_case_node_id', 'user_id']);
            $table->foreign(['workflow_case_id', 'workflow_case_node_id'])
                ->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->cascadeOnDelete();
            $table->index(['workflow_case_node_id', 'workflow_case_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_case_confirm_targets');
    }
};
