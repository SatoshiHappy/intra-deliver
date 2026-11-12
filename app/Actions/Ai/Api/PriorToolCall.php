<?php

namespace App\Actions\Ai\Api;

use App\Enums\Ai\AiToolCallDecision;

/**
 * 同じステップの前の呼び出し 1 つ(道具の約束の形の外の検証が受ける物 = docs の 設計/AI/道具と権限/README.md 10.1)。どの段で終わったかによらず、
 * 呼び出しの順に入る。引数は形の検証を通った引数で、通らなかった呼び出し(無い道具・形の誤り・形の検証の中の包みの中の例外)は null。
 */
final readonly class PriorToolCall
{
    /**
     * @param  array<string, mixed>|null  $arguments
     */
    public function __construct(
        public string $callId,
        public string $toolName,
        public ?array $arguments,
        public AiToolCallDecision $decision,
    ) {}
}
