<?php

namespace UpdateCheck;

/**
 * Markdown の部品。外から取った文字列(タグ名・パッケージ名・脆弱性の題名など)は、バッククォート・縦線・改行を除いてからコードで囲む
 * (@ や # による通知・リンクと、表の崩れを防ぐ。設計/更新チェック.md 3 節)。
 */
final class Markdown
{
    public const CELL_LIMIT = 200;

    public static function code(string $text): string
    {
        $clean = trim((string) preg_replace('/[`|\r\n]+/u', ' ', $text));

        if ($clean === '') {
            return '(空)';
        }

        if (Text::length($clean) > self::CELL_LIMIT) {
            $clean = Text::cut($clean, self::CELL_LIMIT).'…';
        }

        return '`'.$clean.'`';
    }

    /**
     * @param  list<string>  $headers  見出し(固定の文言)
     * @param  list<list<string>>  $rows  セルは組み立て済みの Markdown
     */
    public static function table(array $headers, array $rows): string
    {
        $lines = ['| '.implode(' | ', $headers).' |', '|'.str_repeat(' --- |', count($headers))];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }

        return implode("\n", $lines);
    }
}
