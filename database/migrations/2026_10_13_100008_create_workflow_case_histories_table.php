<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 履歴(docs の 設計/ワークフロー/データモデル/表.md 2.8)と、案件のノードが処理済みにした履歴の行を指す複合外部キー
 * workflow_case_nodes (id, workflow_case_history_id)(表.md 2.6。履歴の表の後でないと作れないので最後に足し、down で先に消す = 直列化と検査.md 4 節)。
 * 63 バイトを超えて切り詰められる名前が重ならないように、処理したノード・差戻し先の索引は (…_node_id, workflow_case_id) の列の順、
 * 差戻し先 ≠ 差戻し元の検査制約は workflow_case_node_id <> send_back_workflow_case_node_id の順に書く(直列化と検査.md 4 節の手当て 1・2)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_case_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('workflow_case_node_id');
            $table->string('action', 20);
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->string('comment', 4000)->nullable();
            $table->bigInteger('send_back_workflow_case_node_id')->nullable();
            $table->jsonb('record_snapshot')->nullable();
            $table->timestampsTz();

            $table->unique(['workflow_case_node_id', 'id']);
            $table->foreign(['workflow_case_id', 'workflow_case_node_id'])->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->restrictOnDelete();
            $table->foreign(['workflow_case_id', 'send_back_workflow_case_node_id'])->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->restrictOnDelete();
            $table->index(['workflow_case_id', 'id']);
            $table->index(['workflow_case_node_id', 'workflow_case_id']);
            $table->index(['send_back_workflow_case_node_id', 'workflow_case_id']);
        });

        DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_action_check CHECK (action IN ('apply', 'reapply', 'approve', 'approve_early', 'reject', 'send_back', 'pull_back', 'withdraw'))");
        DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_comment_check CHECK (comment <> '')");
        DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_record_snapshot_check CHECK (jsonb_typeof(record_snapshot) = 'object')");
        DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_action_send_back_workflow_case_node_id_check CHECK ((action = 'send_back') = (send_back_workflow_case_node_id IS NOT NULL))");
        DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_action_record_snapshot_check CHECK ((action IN ('apply', 'reapply')) = (record_snapshot IS NOT NULL))");
        DB::statement('ALTER TABLE workflow_case_histories ADD CONSTRAINT workflow_case_histories_workflow_case_node_id_send_back_workflow_case_node_id_check CHECK (workflow_case_node_id <> send_back_workflow_case_node_id)');

        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->foreign(['id', 'workflow_case_history_id'])->references(['workflow_case_node_id', 'id'])->on('workflow_case_histories')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->dropForeign(['id', 'workflow_case_history_id']);
        });

        Schema::dropIfExists('workflow_case_histories');
    }
};
