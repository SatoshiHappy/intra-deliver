<?php

namespace App\Enums\Ai;

/**
 * 会話の型の実行が止めたで終わった理由(docs の 設計/AI/実行の記録.md 2.1 の「終わり方の理由」、データと失敗と検査.md 7 節。表 ai_runs の stop_reason)。
 * 値は DB の検査制約と同じ一覧。費用の上限のときだけ、超えた期間(stop_budget_period)を添える。
 */
enum AiRunStopReason: string
{
    /** 利用者が止めた(止める口・「やめる」・会話が無い) */
    case User = 'user';

    /** 実行の時間の上限 */
    case TimeLimit = 'time_limit';

    /** 回数の上限 */
    case TurnLimit = 'turn_limit';

    /** 長さ(送る量が止める量を超える・接続先が長すぎるとして断った) */
    case Length = 'length';

    /** 費用の上限 */
    case Budget = 'budget';
}
