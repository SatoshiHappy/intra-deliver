<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 案件のノード(docs の 設計/ワークフロー/データモデル/表.md 2.6)。処理済みにした履歴の行を指す複合外部キー (id, workflow_case_history_id) は、
 * 履歴の表ができた後でないと作れないので、workflow_case_histories のマイグレーションが足す(直列化と検査.md 4 節)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_case_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_id')->constrained()->cascadeOnDelete();
            $table->integer('position');
            $table->string('code', 100);
            $table->string('name', 1000);
            $table->string('kind', 20);
            $table->bigInteger('authz_subject_group_id');
            // 定数の生成列(下の (authz_subject_group_id, purpose) の外部キーで、写した処理対象者の指定が用途 selection の対象者であることを
            // DB が止める = 表.md 2.6。アプリは書かず読まない)
            $table->string('purpose', 20)->storedAs("'selection'");
            $table->string('state', 20);
            $table->bigInteger('workflow_case_history_id')->nullable();
            $table->timestampsTz();

            $table->unique(['workflow_case_id', 'position']);
            $table->unique(['workflow_case_id', 'code']);
            $table->unique(['workflow_case_id', 'id']);
            $table->foreign(['authz_subject_group_id', 'purpose'])->references(['id', 'purpose'])->on('authz_subject_groups')->restrictOnDelete();
            $table->index(['authz_subject_group_id', 'purpose']);
            $table->index(['workflow_case_history_id', 'id']);
        });

        DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_code_check CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$')");
        DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_name_check CHECK (name <> '')");
        DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_kind_check CHECK (kind IN ('apply', 'approve'))");
        DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_state_check CHECK (state IN ('unreached', 'waiting', 'reapply', 'done'))");
        DB::statement('ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_position_check CHECK (position >= 1)');
        DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_position_kind_check CHECK ((position = 1) = (kind = 'apply'))");
        DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_kind_state_check CHECK ((kind = 'apply' AND state IN ('unreached', 'reapply', 'done')) OR (kind = 'approve' AND state IN ('unreached', 'waiting', 'done')))");
        DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_state_workflow_case_history_id_check CHECK ((state = 'done') = (workflow_case_history_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX workflow_case_nodes_workflow_case_id_unique ON workflow_case_nodes (workflow_case_id) WHERE state IN ('waiting', 'reapply')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_case_nodes');
    }
};
