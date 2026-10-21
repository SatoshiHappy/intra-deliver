<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * フローに「添付を使う」is_attachment_enabled を足す(docs の 設計/ワークフロー/申請の周辺/データモデル.md の 2.1 節)。
 * 既定は true(既存のフローも使うのまま増やす = 同じ文書の 5 節)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_flows', function (Blueprint $table) {
            $table->boolean('is_attachment_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations(使わないにしたフローがあるときは、一時保存の添付の表のマイグレーションの down が先に止める)。
     */
    public function down(): void
    {
        Schema::table('workflow_flows', function (Blueprint $table) {
            $table->dropColumn('is_attachment_enabled');
        });
    }
};
