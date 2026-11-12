<?php

namespace App\Enums\Ai;

/**
 * 判定の関数が断った理由(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の表 1 の 2 段目。表 ai_tool_calls の decision_reason =
 * 設計/AI/実行の記録.md 2.4)。規則による断り(範囲の外・有効でない・権限が無い)と、見つからない(表示の止めか業務モジュールが無い)。
 */
enum AiToolDenialReason: string
{
    /** 渡せる範囲の外(B2・B12・B9) */
    case OutOfScope = 'out_of_scope';

    /** 依頼者がその日に有効でない(B2・B12・B9) */
    case Inactive = 'inactive';

    /** 業務の権限が無い(B2・B12・B9) */
    case NoPermission = 'no_permission';

    /** 見つからない(B10) */
    case NotFound = 'not_found';
}
