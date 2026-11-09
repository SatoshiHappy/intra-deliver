<?php

namespace App\Enums\Ai;

/**
 * AI の実行の状態(docs の 設計/AI/実行の記録.md 2.1。表 ai_runs の status)。値は DB の検査制約と同じ一覧。
 * 遷移は Queued → Running → Succeeded / Failed。Queued から Failed に移るのは、ジョブの failed() が呼ばれたとき(worker_lost)と、
 * 種類の実装が引けないとき(completion。docs の 設計/AI/実行と費用/README.md 4 節)。会話の型だけ、加えて確認待ち・止めたで終わる
 * (移り方 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.11。会話の型でない行は検査制約が止める)。
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

    /** 確認待ちで終わった(会話の型だけ。確認への答えは次の実行) */
    case AwaitingConfirmation = 'awaiting_confirmation';

    /** 止めたで終わった(会話の型だけ。理由 = {@see AiRunStopReason})。失敗ではない */
    case Stopped = 'stopped';

    /** 終わった(成功・失敗・確認待ち・止めた。終わった実行はもう状態を変えない) */
    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::AwaitingConfirmation, self::Stopped], true);
    }
}
