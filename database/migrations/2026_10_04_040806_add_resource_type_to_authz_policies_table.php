<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 認可のポリシーにアクションのリソースタイプを足し、一意をリソースグループ・リソースタイプ・アクション・対象者の組にする
 * (docs の 設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies。アクションはリソースタイプとの組で 1 つの操作)。
 *
 * 列は NOT NULL で既定値を持たないので、ポリシーの行が残っていれば up は失敗する(タイプを推し量って埋めない)。
 * down は元の列と一意に戻す。リソースタイプだけ違う行が残っていれば、元の一意を作り直すところで失敗する(行を黙って消さない)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('authz_policies', function (Blueprint $table) {
            $table->string('resource_type', 255);
            $table->dropUnique(['authz_resource_group_id', 'authz_subject_group_id', 'action']);
            $table->unique(['authz_resource_group_id', 'resource_type', 'action', 'authz_subject_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('authz_policies', function (Blueprint $table) {
            $table->dropUnique(['authz_resource_group_id', 'resource_type', 'action', 'authz_subject_group_id']);
            $table->dropColumn('resource_type');
            $table->unique(['authz_resource_group_id', 'authz_subject_group_id', 'action']);
        });
    }
};
