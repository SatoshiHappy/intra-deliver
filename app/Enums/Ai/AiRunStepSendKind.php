<?php

namespace App\Enums\Ai;

/**
 * ステップの送信の種別(docs の 設計/AI/実行の記録.md 2.3。表 ai_run_steps の send_kind)。値は DB の検査制約と同じ一覧で、種別ごとの用途も
 * 検査制約が止める。
 */
enum AiRunStepSendKind: string
{
    /** 回の答え */
    case Turn = 'turn';

    /** 出力の上限で切れた答えの続きを書かせる送信 */
    case Continuation = 'continuation';

    /** 要約 */
    case Compaction = 'compaction';

    /** 要約の予備 */
    case CompactionFallback = 'compaction_fallback';

    /** 会話の題 */
    case Title = 'title';

    /** ファイルを作る道具の中の呼び出し */
    case CreateFile = 'create_file';

    /** ファイルを作る道具の中の作業場の続き */
    case WorkspaceContinuation = 'workspace_continuation';
}
