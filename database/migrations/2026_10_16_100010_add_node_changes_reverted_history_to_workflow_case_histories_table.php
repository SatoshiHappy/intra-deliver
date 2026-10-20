<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 履歴に、操作の前のノードの状態の写しと、引戻した行を足す(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.7、直列化と検査.md 5 節の (8))。
 * 段 2 の索引 (workflow_case_id, id) を一意にし、引戻した行と指定(2026_10_16_100011)の複合外部キーの参照先にする。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->jsonb('node_changes')->nullable();
            $table->bigInteger('reverted_workflow_case_history_id')->nullable();

            $table->dropIndex(['workflow_case_id', 'id']);
            $table->unique(['workflow_case_id', 'id']);
            $table->foreign(['workflow_case_id', 'reverted_workflow_case_history_id'])->references(['workflow_case_id', 'id'])->on('workflow_case_histories')->restrictOnDelete();
            $table->index(['reverted_workflow_case_history_id', 'workflow_case_id']);
        });

        DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_action_node_changes_check CHECK ((action = 'pull_back') = (node_changes IS NULL))");
        DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_node_changes_check CHECK (jsonb_typeof(node_changes) = 'array')");
        DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_action_reverted_workflow_case_history_id_check CHECK ((action = 'pull_back') = (reverted_workflow_case_history_id IS NOT NULL))");
        DB::statement('ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_reverted_workflow_case_history_id_id_check CHECK (reverted_workflow_case_history_id < id)');
        DB::statement('CREATE UNIQUE INDEX workflow_case_histories_reverted_workflow_case_history_id_unique ON workflow_case_histories (reverted_workflow_case_history_id) WHERE reverted_workflow_case_history_id IS NOT NULL');
    }

    /**
     * Reverse the migrations(列を消すと、その列を含む検査制約・外部キー・索引も消える)。
     */
    public function down(): void
    {
        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->dropForeign(['workflow_case_id', 'reverted_workflow_case_history_id']);
        });

        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->dropColumn(['node_changes', 'reverted_workflow_case_history_id']);
            $table->dropUnique(['workflow_case_id', 'id']);
            $table->index(['workflow_case_id', 'id']);
        });
    }
};
