<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 案件に、申請の時のフローの「添付を使う」の写し is_attachment_enabled を足す(docs の 設計/ワークフロー/申請の周辺/データモデル.md の
 * 3.4 節)。一時保存は NULL。既存の申請した案件はフローの既定と同じ true で埋めてから検査制約を足す(同じ文書の 5 節)。
 */
return new class extends Migration
{
    private const CHECK = 'workflow_cases_state_is_attachment_enabled_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_cases', function (Blueprint $table) {
            $table->boolean('is_attachment_enabled')->nullable();
        });

        DB::update("UPDATE workflow_cases SET is_attachment_enabled = true WHERE state <> 'draft'");
        DB::statement('ALTER TABLE workflow_cases ADD CONSTRAINT '.self::CHECK
            ." CHECK ((state = 'draft') = (is_attachment_enabled IS NULL))");
    }

    /**
     * Reverse the migrations(列を消すと、その列を含む検査制約も消える。写しが false の案件があるときは、一時保存の添付の表の
     * マイグレーションの down が先に止める)。
     */
    public function down(): void
    {
        Schema::table('workflow_cases', function (Blueprint $table) {
            $table->dropColumn('is_attachment_enabled');
        });
    }
};
