<?php

namespace App\Enums\Ai;

/**
 * ステップの状態(docs の 設計/AI/実行の記録.md 2.3。表 ai_run_steps の status)。値は DB の検査制約と同じ一覧。
 */
enum AiRunStepStatus: string
{
    /** 処理中 */
    case Running = 'running';

    /** 成功 */
    case Succeeded = 'succeeded';

    /** 失敗 */
    case Failed = 'failed';

    /** 止めた(応答を読む途中に止める印を見て流れを閉じた) */
    case Stopped = 'stopped';
}
