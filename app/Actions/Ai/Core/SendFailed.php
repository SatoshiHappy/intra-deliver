<?php

namespace App\Actions\Ai\Core;

/**
 * 1 回の送信の失敗(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の口の表)。長すぎるで接続先が量を返したときは、実際の量と上限を持つ。
 */
final readonly class SendFailed implements SendResult
{
    public function __construct(
        public SendFailure $failure,
        public ?int $actualTokens = null,
        public ?int $limitTokens = null,
    ) {}
}
