<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 案件のノードに、ルートのノードの「添付を追加できない」の写し prevents_attachment_add を足す(docs の
 * 設計/ワークフロー/申請の周辺/データモデル.md の 3.1 節)。既存の行は既定の false。式はルートのノードと同じ。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->boolean('prevents_attachment_add')->default(false);
        });

        DB::statement(
            'ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_prevents_attachment_add_kind_check '
            ."CHECK (NOT prevents_attachment_add OR kind IN ('approve', 'serial', 'parallel'))",
        );
    }

    /**
     * Reverse the migrations(列を消すと、その列を含む検査制約も消える。真の行があるときは、一時保存の添付の表の
     * マイグレーションの down が先に止める)。
     */
    public function down(): void
    {
        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->dropColumn('prevents_attachment_add');
        });
    }
};
