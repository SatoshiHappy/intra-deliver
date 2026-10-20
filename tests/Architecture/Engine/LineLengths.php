<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * 物理行の字数(汎用)。字数 = UTF-8 の文字の数(全角も 1。改行の文字は数えない)。コメント・文字列の行も同じに数える。
 */
final class LineLengths
{
    /**
     * ファイル $files(根 $root からの相対パス)ごとの、字数が $limit を超える行の数(超える行が 1 つ以上のファイルだけ)。
     *
     * @param  list<string>  $files
     * @return array<string, int>
     *
     * @throws UnexpectedValueException 読めないとき
     */
    public static function overLimit(string $root, array $files, int $limit): array
    {
        $counts = [];

        foreach ($files as $file) {
            $content = TrackedFiles::contents($root, $file);
            $over = count(array_filter(
                preg_split('/\r\n|\n|\r/', $content) ?: [],
                fn (string $line): bool => mb_strlen($line, 'UTF-8') > $limit,
            ));

            if ($over > 0) {
                $counts[$file] = $over;
            }
        }

        return $counts;
    }
}
