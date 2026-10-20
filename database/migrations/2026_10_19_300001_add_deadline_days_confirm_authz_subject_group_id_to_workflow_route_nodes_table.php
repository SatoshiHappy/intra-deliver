<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ルートのノードに処理期限の日数と確認する人を足す(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 2.3 節)。
 * 確認する人は処理対象者の指定と同じ生成列 purpose で、用途 selection の対象者だけを指す。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->integer('deadline_days')->nullable();
            $table->bigInteger('confirm_authz_subject_group_id')->nullable();

            $table->foreign(['confirm_authz_subject_group_id', 'purpose'])
                ->references(['id', 'purpose'])->on('authz_subject_groups')->restrictOnDelete();
            $table->index(['confirm_authz_subject_group_id', 'purpose']);
        });

        DB::statement(
            'ALTER TABLE workflow_route_nodes ADD CONSTRAINT workflow_route_nodes_deadline_days_check '
            .'CHECK (deadline_days BETWEEN 0 AND 99)',
        );
        DB::statement(
            'ALTER TABLE workflow_route_nodes ADD CONSTRAINT workflow_route_nodes_deadline_days_kind_check '
            ."CHECK (deadline_days IS NULL OR kind IN ('approve', 'serial', 'parallel'))",
        );
        DB::statement(
            'ALTER TABLE workflow_route_nodes ADD CONSTRAINT '
            .'workflow_route_nodes_confirm_authz_subject_group_id_kind_check '
            ."CHECK (confirm_authz_subject_group_id IS NULL OR kind IN ('apply', 'approve'))",
        );
    }

    /**
     * Reverse the migrations(列を消すと、その列を含む検査制約・索引も消える)。
     */
    public function down(): void
    {
        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->dropForeign(['confirm_authz_subject_group_id', 'purpose']);
        });

        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->dropColumn(['deadline_days', 'confirm_authz_subject_group_id']);
        });
    }
};
