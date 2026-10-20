<?php

namespace App\Enums\Ai;

/**
 * AI の実行の状態(docs の 設計/AI/README.md 2.1。表 ai_runs の status)。値は DB の検査制約と同じ一覧。
 * 遷移は Queued → Running → Succeeded / Failed。Queued から Failed に移るのは、ジョブの failed() が呼ばれたとき(worker_lost)と、
 * 種類の実装が引けないとき(completion。docs の 設計/AI/実行と費用.md 4 節)。
 */
enum AiRunStatus: string
{
    /** 依頼を受け、ジョブを待っている */
    case Queued = 'queued';

    /** ジョブが LLM を呼んでいる */
    case Running = 'running';

    /** 結果を書き、種類の実装の complete まで終えた */
    case Succeeded = 'succeeded';

    /** 失敗した(失敗の種類 = {@see AiRunErrorCode}) */
    case Failed = 'failed';

    /** 終わった(成功か失敗。終わった実行はもう状態を変えない) */
    public function isFinished(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
