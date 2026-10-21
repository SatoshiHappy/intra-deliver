<?php

namespace App\Actions\Meeting;

use Generator;

/**
 * 取り込むテキストを 1 行ずつ読み進める手助け(全文を行の配列に展開しない = 行の数の上限で途中で止めるため。docs の
 * 設計/業務ツール/会議/README.md の 2 節の「行に分ける単位」)。改行は CRLF・CR・LF を受ける。
 */
final class TextLines
{
    /**
     * 行(改行を除く)を先頭から 1 つずつ。
     *
     * @return Generator<int, string>
     */
    public static function of(string $content): Generator
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $length = strlen($normalized);
        $offset = 0;

        while ($offset < $length) {
            $end = strpos($normalized, "\n", $offset);
            $next = $end === false ? $length : $end;

            yield substr($normalized, $offset, $next - $offset);

            $offset = $next + 1;
        }
    }

    /**
     * 空の行(前後の空白を除いて空)で区切った塊(空でない行の並び)を先頭から 1 つずつ。
     *
     * @return Generator<int, non-empty-list<string>>
     */
    public static function blocks(string $content): Generator
    {
        $block = [];

        foreach (self::of($content) as $line) {
            if (self::trim($line) !== '') {
                $block[] = $line;
            } elseif ($block !== []) {
                yield $block;

                $block = [];
            }
        }

        if ($block !== []) {
            yield $block;
        }
    }

    /**
     * 前後の空白(全角の空白など Unicode の空白を含む)を除く。UTF-8 として正しい文字列だけを渡す。
     */
    public static function trim(string $value): string
    {
        return (string) preg_replace('/\A\s+|\s+\z/u', '', $value);
    }
}
