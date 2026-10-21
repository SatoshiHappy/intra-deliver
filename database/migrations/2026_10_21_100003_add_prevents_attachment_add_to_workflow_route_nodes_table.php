<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ルートのノードに「添付を追加できない」prevents_attachment_add を足す(docs の 設計/ワークフロー/申請の周辺/データモデル.md の
 * 2.2 節)。既存の行は既定の false(追加できる)。真を持てるのは承認・横配置・縦配置だけ。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->boolean('prevents_attachment_add')->default(false);
        });

        DB::statement(
            'ALTER TABLE workflow_route_nodes ADD CONSTRAINT workflow_route_nodes_prevents_attachment_add_kind_check '
            ."CHECK (NOT prevents_attachment_add OR kind IN ('approve', 'serial', 'parallel'))",
        );
    }

    /**
     * Reverse the migrations(列を消すと、その列を含む検査制約も消える。真の行があるときは、一時保存の添付の表の
     * マイグレーションの down が先に止める)。
     */
    public function down(): void
    {
        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->dropColumn('prevents_attachment_add');
        });
    }
};
