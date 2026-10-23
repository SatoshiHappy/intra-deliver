<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI の実行に、依頼の時点の訪問者の ID を足す(docs の 設計/AI/README.md 2.1、持ち方 = 設計/AI/データと失敗と検査.md 6 節)。
 * 無い依頼と前からある行は NULL。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->uuid('visitor_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('visitor_id');
        });
    }
};
