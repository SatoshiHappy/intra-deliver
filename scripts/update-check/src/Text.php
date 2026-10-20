<?php

namespace UpdateCheck;

/**
 * UTF-8 の文字数の扱い(mbstring に頼らない)。継続バイト(0x80〜0xBF)以外のバイトが文字の先頭。
 */
final class Text
{
    public static function length(string $text): int
    {
        return strlen($text) - (int) preg_match_all('/[\x80-\xBF]/', $text);
    }

    /**
     * 先頭の $characters 文字。
     */
    public static function cut(string $text, int $characters): string
    {
        $count = 0;
        $bytes = strlen($text);

        for ($index = 0; $index < $bytes; $index++) {
            if ((ord($text[$index]) & 0xC0) === 0x80) {
                continue;
            }

            if ($count === $characters) {
                return substr($text, 0, $index);
            }

            $count++;
        }

        return $text;
    }

    /**
     * 先頭から $characters 文字以内で、完成した行の境目で切る(行の途中で切らない。末尾の改行は含めない)。1 行目が収まらないときは文字数で切る。
     */
    public static function cutLines(string $text, int $characters): string
    {
        $head = self::cut($text, $characters);

        if (strlen($head) === strlen($text) || ($text[strlen($head)] ?? '') === "\n") {
            return rtrim($head, "\n");
        }

        $newline = strrpos($head, "\n");

        return $newline === false ? $head : substr($head, 0, $newline);
    }
}
