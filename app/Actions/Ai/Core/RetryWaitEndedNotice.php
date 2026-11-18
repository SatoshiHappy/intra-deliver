<?php

namespace App\Actions\Ai\Core;

/**
 * 送り直しの待ちの終わりの知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3)。
 */
final readonly class RetryWaitEndedNotice implements CoreNotice
{
    public function __construct(public int $stepId) {}
}
