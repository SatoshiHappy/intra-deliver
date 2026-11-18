<?php

namespace App\Actions\Ai\Core;

/**
 * 途中の文の差分の知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3。回の答えと続きの送信だけ。途中の表示 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.6)。
 */
final readonly class TextDeltaNotice implements CoreNotice
{
    public function __construct(
        public int $stepId,
        public string $delta,
    ) {}
}
