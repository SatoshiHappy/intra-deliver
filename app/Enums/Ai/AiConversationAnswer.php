<?php

namespace App\Enums\Ai;

/**
 * 確認に答える会話の依頼の答え(docs の 設計/AI/実行の記録.md 2.1 の「答え」。表 ai_runs の answer)。値は DB の検査制約と同じ一覧。
 * 答えの実行だけが持つ(前の実行があり、題を求めない)。
 */
enum AiConversationAnswer: string
{
    /** 実行 */
    case Approve = 'approve';

    /** やめる */
    case Reject = 'reject';
}
