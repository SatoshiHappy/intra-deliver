<?php

namespace App\Actions\Ai\Core;

/**
 * ステップを止めたの知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3。応答を読む途中の確かめで流れを閉じた)。
 */
final readonly class StepStoppedNotice implements CoreNotice
{
    public function __construct(
        public int $stepId,
        public StopKind $stop,
    ) {}
}
