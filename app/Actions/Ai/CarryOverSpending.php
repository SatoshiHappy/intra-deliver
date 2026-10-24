<?php

namespace App\Actions\Ai;

use App\Actions\Reset\Api\ResetParticipant;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiSpendingCarryover;
use App\Queries\Ai\RunSpending;
use Illuminate\Support\Facades\DB;

/**
 * 毎日の初期化に加わる口の Ai の実装(docs の 設計/AI/実行と費用.md 5.2、呼ぶ時と保証 = 設計/公開の配備/毎日の初期化.md 7 節)。
 * 額と日は上限の確認と同じ式({@see RunSpending})。待ち(queued)の実行は接続先を呼んでいないので数えない(ADR 0024 の決定 3)。
 * 費用の鍵({@see RunBudget::lock()})を取るのは、上限の確認と行の追加の途中に割り込まないため。
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

            $table = (new AiSpendingCarryover)->getTable();
            $runs = (new AiRun)->getTable();
            DB::statement(
                "INSERT INTO {$table} (budget, spent_on, cost_usd)"
                .' SELECT budget, '.RunSpending::runDaySql().', sum('.RunSpending::RUN_COST_SQL.')'
                ." FROM {$runs} WHERE status <> ? GROUP BY 1, 2"
                ." ON CONFLICT (budget, spent_on) DO UPDATE SET cost_usd = {$table}.cost_usd + EXCLUDED.cost_usd",
                [AiRunStatus::Queued->value],
            );
        });
    }
}
