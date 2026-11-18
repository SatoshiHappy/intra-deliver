<?php

namespace App\Actions\Ai\Core;

/**
 * ループが使う文の口が組んだ 1 つの送信の文(docs の 設計/AI/設定とプロンプト/README.md 11.4 の「返す物」): 指示の文と、送る履歴の先頭と
 * 末尾に足す文(送るときだけ足し、保存しない)。
 */
final readonly class ComposedInstructions
{
    /**
     * @param  list<string>  $atStart  履歴の先頭に足す文(先頭の日付の文)
     * @param  list<string>  $atEnd  履歴の末尾に足す文(要約の依頼の文)
     */
    public function __construct(
        public string $system,
        public array $atStart,
        public array $atEnd,
    ) {}
}
