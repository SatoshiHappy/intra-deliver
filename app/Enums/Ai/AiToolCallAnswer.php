<?php

namespace App\Enums\Ai;

/**
 * 道具の呼び出しの確認の答え(docs の 設計/AI/実行の記録.md 2.4。表 ai_tool_calls の answer)。値は DB の検査制約と同じ一覧。
 * 実行・やめるは答えの実行の答え({@see AiConversationAnswer})と同じ値で、答えずに次の文はカードに答えずに文を送った実行が書く
 * (設計/AI/エージェントの実行/README.md 9.3)。
 */
enum AiToolCallAnswer: string
{
    /** 実行 */
    case Approve = 'approve';

    /** やめる */
    case Reject = 'reject';

    /** 答えずに次の文 */
    case NextText = 'next_text';
}
