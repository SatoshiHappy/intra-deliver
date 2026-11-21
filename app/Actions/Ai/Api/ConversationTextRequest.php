<?php

namespace App\Actions\Ai\Api;

/**
 * 会話の依頼(文を送る。docs の 設計/AI/エージェントの実行/口と契約.md 9.12)の入力。前の実行はその会話の直前の実行(会話の最初の文は無し)。
 */
final readonly class ConversationTextRequest
{
    public function __construct(
        public string $kindCode,
        public int $definitionId,
        public string $text,
        public ?int $previousRunId,
        public bool $wantsTitle,
    ) {}
}
