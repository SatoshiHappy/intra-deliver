<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI の実行に、依頼の時点に依頼者が選んでいた組織と予算の区分を足す(docs の 設計/AI/README.md 2.1)。
 * 前からある行の区分は default(NULL 可で足し、既存の行に書いてから NOT NULL にする)。区分の一覧は設定が持ち、DB に一覧の検査制約を置かない。
 * 区分ごとの上限の集計のため、(budget, created_at) の索引を足し、created_at だけの索引を外す。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->foreignId('selected_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('budget', 32)->nullable();
        });

        DB::table('ai_runs')->update(['budget' => 'default']);

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('budget', 32)->nullable(false)->change();
            $table->dropIndex(['created_at']);
            $table->index(['budget', 'created_at']);
        });

        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_budget_check CHECK (budget <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT ai_runs_budget_check');

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex(['budget', 'created_at']);
            $table->index('created_at');
            $table->dropConstrainedForeignId('selected_department_id');
            $table->dropColumn('budget');
        });
    }
};
