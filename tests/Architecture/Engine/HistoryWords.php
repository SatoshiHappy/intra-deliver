<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * 語の出現の数(汎用)。参照の語と、その引用の部分({@see ReferenceWords} が見分けた部分)は数えない
 * (文書名・引用に同じ字が入っていても、それは文書の参照)。テキストとして読めないファイル(NUL を含むか UTF-8 でないもの)は見ない。
 */
final class HistoryWords
{
    /**
     * ファイル $files(根 $root からの相対パス)ごとの、正規表現 $pattern の一致の数(一致が 1 つ以上のファイルだけ)。
     *
     * @param  list<string>  $files
     * @return array<string, int>
     *
     * @throws UnexpectedValueException 読めないとき
     */
    public static function count(string $root, array $files, string $pattern): array
    {
        $counts = [];

        foreach ($files as $file) {
            $content = TrackedFiles::contents($root, $file);

            if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
                continue;
            }

            $found = array_sum(array_map(
                fn (string $line): int => (int) preg_match_all($pattern, self::withoutReferences($line)),
                preg_split('/\r\n|\n|\r/', $content) ?: [],
            ));

            if ($found > 0) {
                $counts[$file] = $found;
            }
        }

        return $counts;
    }

    /**
     * 行 $line の参照の語と、それに続く節と引用を空白にした行(バイトの長さは変えない)。
     */
    private static function withoutReferences(string $line): string
    {
        foreach (ReferenceWords::inLine($line) as $reference) {
            $extent = $reference['extent'];
            $line = substr_replace($line, str_repeat(' ', $extent), $reference['offset'], $extent);
        }

        return $line;
    }
}
