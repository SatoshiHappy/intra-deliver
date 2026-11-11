<?php

namespace App\Actions\Ai;

use App\Actions\Reset\Api\ResetParticipant;
use App\Models\Ai\AiSpendingCarryover;
use App\Queries\Ai\RunSpending;
use Illuminate\Support\Facades\DB;

/**
 * 毎日の初期化に加わる口の Ai の実装(docs の 設計/AI/実行と費用/接続先と費用.md 5.2、呼ぶ時と保証 = 設計/公開の配備/毎日の初期化.md 7 節)。
 * 数える行・額・日と待ち(queued)の実行を除くことは、上限の確認と同じ式({@see RunSpending::carryOverRowsSql()}。会話の型はステップの行から
 * 東京の日で足し込む)。
 * 表の名前は RunSpending の字の定数(式と同じ置き場)。費用の鍵({@see RunBudget::lock()})を取るのは、上限の確認と行の追加の途中に割り込まないため。
 * 起動(App\Providers\AppServiceProvider)がタグ {@see ResetParticipant::TAG} で登録する。
 */
class CarryOverSpending implements ResetParticipant
{
    public function __construct(private RunBudget $budget) {}

    public function keptTables(): array
    {
        return [(new AiSpendingCarryover)->getTable()];
    }

    public function beforeTruncate(): void
    {
        DB::transaction(function (): void {
            $this->budget->lock();

            $table = RunSpending::CARRYOVERS_TABLE;
            $rows = RunSpending::carryOverRowsSql(RunSpending::RUNS_TABLE, RunSpending::STEPS_TABLE);
            DB::statement(
                "INSERT INTO {$table} (budget, spent_on, cost_usd) {$rows}"
                ." ON CONFLICT (budget, spent_on) DO UPDATE SET cost_usd = {$table}.cost_usd + EXCLUDED.cost_usd",
            );
        });
    }
}
