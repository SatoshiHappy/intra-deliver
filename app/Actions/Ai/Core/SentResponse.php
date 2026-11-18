<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;

/**
 * 1 回の送信が送り終えた応答(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の口の表)。
 *
 * @phpstan-import-type Usage from ConversationItem
 */
final readonly class SentResponse implements SendResult
{
    /**
     * @param  list<ConversationItem>  $items  確定したモデルの文の項目
     * @param  Usage  $usage  使用量
     * @param  array<string, mixed>|null  $structured  構造化の出力(求めて読めたとき)
     */
    public function __construct(
        public array $items,
        public ResponseFinish $finish,
        public array $usage,
        public ?array $structured,
    ) {}
}
