<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI に送った文と応答の原文の表(docs の 設計/AI/README.md 2.2)。実行 1 回に 1 行(主キー = 実行)。
 * 原文は個人情報を含みうるので、作ってから保存期間(設定 ai_runs.retention_days)を過ぎた行を消す(設計/AI/データと失敗と検査.md 6 節)。ai_runs は残る。
 * ジョブは送る文をこの表から読む(キューの行に文を載せない)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_run_texts', function (Blueprint $table) {
            $table->foreignId('ai_run_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('system_text');
            $table->text('user_text');
            $table->text('output_text')->nullable();
            $table->timestampTz('created_at');

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_run_texts');
    }
};
