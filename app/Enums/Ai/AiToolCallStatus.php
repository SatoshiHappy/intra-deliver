<?php

namespace App\Enums\Ai;

/**
 * 道具の呼び出しの行の状態(docs の 設計/AI/実行の記録.md 2.4。表 ai_tool_calls の status)。値は DB の検査制約と同じ一覧で、
 * 判定と答えの組ごとに許す状態も検査制約が止める。
 */
enum AiToolCallStatus: string
{
    /** 処理中 */
    case Running = 'running';

    /** 確認待ち */
    case AwaitingConfirmation = 'awaiting_confirmation';

    /** 済み */
    case Done = 'done';

    /** 断られた */
    case Rejected = 'rejected';

    /** 道具の誤り */
    case ToolError = 'tool_error';

    /** 取り消し */
    case Cancelled = 'cancelled';

    /** 結果不明 */
    case Unknown = 'unknown';
}
