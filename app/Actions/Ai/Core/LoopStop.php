<?php

namespace App\Actions\Ai\Core;

/**
 * ループの終わり方「止めた」の理由(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の終わり方の表。設計/AI/エージェントの実行/状態の移り方.md 9.11 の終わり方のうち中核が返す値)。
 */
enum LoopStop: string
{
    /** 利用者(確かめの口の止める区分か、会話が無い) */
    case User = 'user';

    /** 時間の上限(確かめの口の止める区分か、送り直しの待ちが待ちの上限より長い) */
    case TimeLimit = 'time_limit';

    /** 費用の上限(確かめの口の止める区分) */
    case CostLimit = 'cost_limit';

    /** 長さ(設計/AI/エージェントの実行/途中の表示と上限.md 9.9) */
    case Length = 'length';

    /** 回数の上限(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.7) */
    case TurnLimit = 'turn_limit';

    /** 確かめの口の止める区分に当たる理由 */
    public static function fromStopKind(StopKind $kind): self
    {
        return match ($kind) {
            StopKind::User => self::User,
            StopKind::TimeLimit => self::TimeLimit,
            StopKind::CostLimit => self::CostLimit,
        };
    }
}
