<?php

namespace Tests\Architecture\Engine;

/**
 * 文の中の文書の参照の語と、その後ろの節・引用を見分ける(汎用。語・節・引用の形 = docs の 設計/コード構成/検査.md 6 節)。
 *
 * @phpstan-type Reference array{
 *     word: string, offset: int, length: int, extent: int, section: string|null, quote: string|null,
 * }
 */
final class ReferenceWords
{
    private const DELIMITERS = '\s`\'"()\[\]{}<>「」『』()、。・,;=*|';

    private const CLOSERS = '[`\'"]?[ \t]*';

    private const EXTENSION = '.md';

    private const EXCLUDED_PREFIXES = ['~/', '/', '@', '$'];

    /**
     * 行 $line の参照の語(出てくる順)。offset と length は行の中のバイトの位置と長さ(語だけ)、extent は語から節と引用の終わりまでの長さ。
     *
     * @return list<Reference>
     */
    public static function inLine(string $line): array
    {
        $references = [];

        foreach ([self::documentPattern(), self::directoryPattern()] as $pattern) {
            preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as [$word, $offset]) {
                if (! self::isExcluded($word)) {
                    $references[] = ['word' => $word, 'offset' => $offset, 'length' => strlen($word)]
                        + self::sectionAndQuote(substr($line, $offset + strlen($word)), strlen($word));
                }
            }
        }

        usort($references, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $references;
    }

    private static function documentPattern(): string
    {
        $delimiters = self::DELIMITERS;

        $extension = preg_quote(self::EXTENSION, '/');

        return "/(?<![^{$delimiters}])([^{$delimiters}]+?{$extension})(?![A-Za-z0-9_\\-\\/.])/u";
    }

    private static function directoryPattern(): string
    {
        $delimiters = self::DELIMITERS;

        // `/` と節の番号の間に空白を求める(`HTTP/1.1` のような語を拾わない)
        return "/(?<![^{$delimiters}])([^{$delimiters}]+\\/)(?=[`'\"]?[ \\t]+(?:\\d+\\.\\d+|\\d+[ \\t]*[節章]))/u";
    }

    private static function isExcluded(string $word): bool
    {
        if (str_contains($word, '://')) {
            return true;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($word, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 語の後ろ $rest の先頭の節(`の` を挟んでよい)と、その後ろの引用(`の「…」` か `「…」`)。どちらも無くてよい。
     *
     * @return array{extent: int, section: string|null, quote: string|null}
     */
    private static function sectionAndQuote(string $rest, int $length): array
    {
        $closers = self::CLOSERS;
        $section = '(?:の[ \t]*)?(?:(\d+(?:\.\d+){1,2})|(\d+)[ \t]*[節章])';
        preg_match("/^{$closers}(?:{$section})?(?:[ \t]*の?「([^」\n]+)」)?/u", $rest, $match, PREG_UNMATCHED_AS_NULL);
        $number = $match[1] ?? $match[2] ?? null;
        $quote = $match[3] ?? null;
        $extent = $number === null && $quote === null ? $length : $length + strlen((string) ($match[0] ?? ''));

        return ['extent' => $extent, 'section' => $number, 'quote' => $quote];
    }

    /**
     * 中身 $content の行(参照の行番号 = 添字 + 1)。
     *
     * @return list<string>
     */
    public static function lines(string $content): array
    {
        return preg_split('/\r\n|\n|\r/', $content) ?: [];
    }

    /**
     * 中身 $content から、行番号つきの参照 $references の範囲(語から節・引用の終わりまで)を消した文。消した所は改行にする(引用は改行を含まないので、消した所の前後をまたいで一致しない)。
     *
     * @param  list<array{0: int, 1: Reference}>  $references
     */
    public static function outside(string $content, array $references): string
    {
        $spans = [];

        foreach ($references as [$number, $reference]) {
            $spans[$number][] = [$reference['offset'], $reference['offset'] + $reference['extent']];
        }

        $lines = [];

        foreach (self::lines($content) as $index => $line) {
            $lines[] = implode("\n", self::outsideOfLine($line, $spans[$index + 1] ?? []));
        }

        return implode("\n", $lines);
    }

    /**
     * 行 $line の、範囲 $spans(バイトの [始め, 終わり)。重なってよい)の外の部分(順に)。
     *
     * @param  list<array{0: int, 1: int}>  $spans
     * @return list<string>
     */
    private static function outsideOfLine(string $line, array $spans): array
    {
        usort($spans, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $pieces = [];
        $cursor = 0;

        foreach ($spans as [$start, $end]) {
            if ($start > $cursor) {
                $pieces[] = substr($line, $cursor, $start - $cursor);
            }

            $cursor = max($cursor, $end);
        }

        $pieces[] = substr($line, $cursor);

        return $pieces;
    }
}
