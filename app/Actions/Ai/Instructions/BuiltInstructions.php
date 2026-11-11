<?php

namespace App\Actions\Ai\Instructions;

/**
 * 指示の組み立ての口が組んだ 1 つの呼び出しの文(docs の 設計/AI/設定とプロンプト/README.md 11.4 の「返す物」): システムの文 1 本と、
 * 送る履歴に足す文の列。
 */
final readonly class BuiltInstructions
{
    /**
     * @param  list<HistoryText>  $history
     */
    public function __construct(
        public string $system,
        public array $history,
    ) {}
}
