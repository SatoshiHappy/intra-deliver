<?php

namespace App\Enums\Ai;

/**
 * 書く時の断りの理由(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の表 2 の結果の理由。表 ai_tool_calls の
 * write_denial_reason = 設計/AI/実行の記録.md 2.4)。表 2 の結果より細かくしない(隠すレコードの有無を値から読めないように)。
 * 値は DB の検査制約と同じ一覧。
 */
enum AiToolWriteDenial: string
{
    /** 引数の形の誤り(P9。状態は道具の誤り) */
    case InvalidShape = 'invalid_shape';

    /** 道具の検証の誤り(P9。状態は道具の誤り) */
    case InvalidInput = 'invalid_input';

    /** 指した前の件が書けなかった(P1) */
    case PriorNotWritten = 'prior_not_written';

    /** 道具か業務モジュールが使えなくなった(P2) */
    case Unavailable = 'unavailable';

    /** 業務モジュールの権限が無い(P3) */
    case NoPermission = 'no_permission';

    /** 見つからない(P4) */
    case NotFound = 'not_found';

    /** ほかの処理の途中(P5) */
    case Held = 'held';

    /** ほかの人が先に直した(P6) */
    case Stale = 'stale';

    /** 入れ物の検証(P7) */
    case Validation = 'validation';

    /** 状態が道具の誤りになる理由か(P9。ほかは断られた) */
    public function isToolError(): bool
    {
        return $this === self::InvalidShape || $this === self::InvalidInput;
    }
}
