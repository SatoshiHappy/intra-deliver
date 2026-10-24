<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI の費用の繰り越しの表(docs の 設計/AI/実行と費用.md 5.2、設計/公開の配備/毎日の初期化.md 1 節)。予算の区分と東京の日ごとの使った額。
 * 公開デモの毎日の初期化が、実行の記録を空にする前に足し込み、初期化では空にしない(残す表は空にする表を外部キーで指さないので、外部キーを持たない)。
 * 区分の一覧は設定が持ち、DB に一覧の検査制約を置かない(ai_runs の budget と同じ)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_spending_carryovers', function (Blueprint $table) {
            $table->string('budget', 32);
            $table->date('spent_on');
            $table->decimal('cost_usd', 12, 6);

            $table->primary(['budget', 'spent_on']);
        });

        DB::statement('ALTER TABLE ai_spending_carryovers'
            ." ADD CONSTRAINT ai_spending_carryovers_budget_check CHECK (budget <> '')");
        DB::statement('ALTER TABLE ai_spending_carryovers'
            .' ADD CONSTRAINT ai_spending_carryovers_cost_usd_check CHECK (cost_usd >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_spending_carryovers');
    }
};
