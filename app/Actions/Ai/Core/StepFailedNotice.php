<?php

namespace App\Actions\Ai\Core;

/**
 * ステップの失敗の知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3。流れの途中で切れて流さない送り方に替えたステップは接続先で閉じる = 4 節)。
 */
final readonly class StepFailedNotice implements CoreNotice
{
    public function __construct(
        public int $stepId,
        public SendFailure $failure,
    ) {}
}
