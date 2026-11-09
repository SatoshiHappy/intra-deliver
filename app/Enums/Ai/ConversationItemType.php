<?php

namespace App\Enums\Ai;

/**
 * 会話の保存の項目の種類(docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「項目の種類」)。
 * 項目の中身の形は Ai の公開の口の項目の型(ConversationItem)が種類ごとに定める。
 */
enum ConversationItemType: string
{
    /** 利用者の文 */
    case UserText = 'user_text';

    /** モデルの文(確定したブロックの列。道具の呼び出しを含む) */
    case ModelText = 'model_text';

    /** 道具の結果(呼び出しの id・結果の文・失敗の印。理由つきの断りでは理由を含む) */
    case ToolResult = 'tool_result';

    /** 中断の印(道具の途中か否か) */
    case Interruption = 'interruption';

    /** 回数の上限の印(画面の履歴に出し、モデルには送らない) */
    case TurnLimit = 'turn_limit';

    /** 要約の境目(モデルには送らない) */
    case CompactBoundary = 'compact_boundary';

    /** 要約の文 */
    case CompactSummary = 'compact_summary';
}
