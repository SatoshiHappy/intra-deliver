<?php

namespace App\Actions\Ai\Instructions;

/**
 * 送る履歴に足す 1 つの文(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.5 の「送る履歴に足す文」): 置く所(履歴の先頭か末尾)と、
 * 会話の保存に書くか(偽 = 送るときだけ)。
 */
final readonly class HistoryText
{
    public function __construct(
        public string $text,
        public bool $atStart,
        public bool $stored,
    ) {}

    /** 履歴の先頭に置き、送るときだけ足す文 */
    public static function sentAtStart(string $text): self
    {
        return new self($text, true, false);
    }

    /** 履歴の末尾に置き、送るときだけ足す文 */
    public static function sentAtEnd(string $text): self
    {
        return new self($text, false, false);
    }
}
