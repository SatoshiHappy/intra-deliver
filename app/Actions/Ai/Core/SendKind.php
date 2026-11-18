<?php

namespace App\Actions\Ai\Core;

/**
 * 会話の中核が送る送信の種別(docs の 設計/AI/会話の中核.md 13.4)。送り直しは種別でなく、同じ送信の何回目かを知らせに載せる。
 * 足すのは約束の変更(実行の記録の側の形が変わるため)。値は実行の記録のステップの送信の種別の値と同じ。
 */
enum SendKind: string
{
    /** 回の答え */
    case Turn = 'turn';

    /** 回の答えが出力の上限で切れた答えの続き */
    case Continuation = 'continuation';

    /** 要約(主の道。古い往復を落とした送り直しと、主の道の答えの続きを含む) */
    case Compaction = 'compaction';

    /** 要約の予備 */
    case CompactionFallback = 'compaction_fallback';

    /** 会話の題 */
    case Title = 'title';
}
