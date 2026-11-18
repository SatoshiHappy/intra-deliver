<?php

namespace App\Actions\Ai\Core;

/**
 * 送り直しの待ちの始まりの知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3。待っている間の長さと何回目かは実行の記録に書く = 4 節)。
 */
final readonly class RetryWaitStartedNotice implements CoreNotice
{
    /**
     * @param  int  $stepId  失敗した試しのステップの番号
     * @param  int  $waitMs  待ちの長さ(ミリ秒)
     * @param  int  $attempt  何回目の送り直しか(1 から)
     * @param  int  $maxAttempts  最大の回数
     * @param  int|null  $errorStatus  誤りの状態
     */
    public function __construct(
        public int $stepId,
        public int $waitMs,
        public int $attempt,
        public int $maxAttempts,
        public ?int $errorStatus,
    ) {}
}
