<?php

namespace Tests\Architecture\Engine\PhpStan;

/**
 * PHP の正規表現(PCRE の区切り文字つきの文字列)が、末尾の改行の前にも一致する `$` を使うかの判定(汎用。数える所 = docs の
 * 設計/コード構成/検査/README.md 6.2)。中の修飾子は効く範囲を読まないので、迷う形は止める側に倒す。
 */
final class RegexEndAnchor
{
    private const BRACKETS = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];

    /**
     * $pattern が正規表現として読め、修飾子 `D`・`m` が無く、文字クラスの外のエスケープされていない `$` を持つか。
     */
    public static function acceptsTrailingNewline(string $pattern): bool
    {
        $parts = self::split($pattern);

        if ($parts === null) {
            return false;
        }

        $turnedOff = self::turnedOffModifiers($parts['body']);
        $modifiers = str_replace($turnedOff, '', $parts['modifiers'].self::leadingModifiers($parts['body']));
        $extended = str_contains($modifiers, 'x');

        return strpbrk($modifiers, 'Dm') === false && self::hasBareDollar($parts['body'], $extended);
    }

    /**
     * 区切り文字・本体・修飾子に分ける。区切り文字が使えない文字(英数字・`\`・空白)か、閉じの区切り文字が無ければ null。
     *
     * @return array{body: string, modifiers: string}|null
     */
    private static function split(string $pattern): ?array
    {
        $pattern = ltrim($pattern);

        if ($pattern === '' || ctype_alnum($pattern[0]) || $pattern[0] === '\\' || ctype_space($pattern[0])) {
            return null;
        }

        $closing = self::BRACKETS[$pattern[0]] ?? $pattern[0];
        $end = strrpos($pattern, $closing);

        if ($end === false || $end === 0) {
            return null;
        }

        $modifiers = substr($pattern, $end + 1);

        if (preg_match('/^[a-zA-Z\s]*\z/', $modifiers) !== 1) {
            return null;
        }

        return ['body' => substr($pattern, 1, $end - 1), 'modifiers' => $modifiers];
    }

    /**
     * 本体の先頭の中の修飾子(`(?mi)`・`(?i-s)` の形。`:` の無いもの)のうち、`-` より前の(付ける側の)もの。
     */
    private static function leadingModifiers(string $body): string
    {
        return preg_match('/\A\(\?([a-zA-Z]*)(?:-[a-zA-Z]*)?\)/', $body, $match) === 1 ? $match[1] : '';
    }

    /**
     * 本体のどこかの中の修飾子(`(?-m)`・`(?i-mx:…)` の形)で `-` より後ろの(外す側の)修飾子(扱い = docs の 設計/コード構成/検査/README.md 6.2)。
     *
     * @return list<string>
     */
    private static function turnedOffModifiers(string $body): array
    {
        preg_match_all('/\(\?[a-zA-Z]*-([a-zA-Z]+)[):]/', $body, $matches);

        return array_values(array_unique(str_split(implode('', $matches[1]))));
    }

    private static function hasBareDollar(string $body, bool $extended): bool
    {
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            if ($body[$i] === '$') {
                return true;
            }

            $i = match ($body[$i]) {
                '\\' => self::afterEscape($body, $i),
                '[' => self::classEnd($body, $i),
                '(' => self::afterComment($body, $i),
                '#' => $extended ? self::lineEnd($body, $i) : $i,
                default => $i,
            };
        }

        return false;
    }

    /**
     * `[` の位置 $i から、文字クラスを閉じる `]` の位置を返す(エスケープと POSIX の文字クラス `[:alpha:]` の中の `]` で閉じない)。
     */
    private static function classEnd(string $body, int $i): int
    {
        $length = strlen($body);

        for ($j = self::afterClassStart($body, $i) + 1; $j < $length; $j++) {
            if ($body[$j] === ']') {
                return $j;
            }

            $j = match ($body[$j]) {
                '\\' => self::afterEscape($body, $j),
                '[' => self::afterPosixClass($body, $j),
                default => $j,
            };
        }

        return $length;
    }

    /**
     * `[` の位置 $j が POSIX の文字クラス(`[:alpha:]`・`[:^digit:]`)の始まりなら、その終わりの位置。でなければ $j。
     */
    private static function afterPosixClass(string $body, int $j): int
    {
        return preg_match('/\G\[:\^?[a-z]+:\]/', $body, $match, 0, $j) === 1 ? $j + strlen($match[0]) - 1 : $j;
    }

    /**
     * `(` の位置 $i がコメント `(?#…)` の始まりなら、閉じの `)` の位置。でなければ $i。
     */
    private static function afterComment(string $body, int $i): int
    {
        if (substr($body, $i, 3) !== '(?#') {
            return $i;
        }

        $end = strpos($body, ')', $i + 3);

        return $end === false ? strlen($body) : $end;
    }

    /**
     * 修飾子 `x` のときのコメント(`#` から改行まで)の終わりの位置。
     */
    private static function lineEnd(string $body, int $i): int
    {
        $end = strpos($body, "\n", $i);

        return $end === false ? strlen($body) : $end;
    }

    /**
     * `\` の位置 $i から、エスケープの最後の文字の位置を返す(`\Q…\E` は `\E` の終わりまで、それ以外は次の 1 字)。
     */
    private static function afterEscape(string $body, int $i): int
    {
        if (($body[$i + 1] ?? '') !== 'Q') {
            return $i + 1;
        }

        $end = strpos($body, '\\E', $i + 2);

        return $end === false ? strlen($body) : $end + 1;
    }

    /**
     * `[` の位置 $i から、文字クラスの先頭の `^` と、先頭に置いた文字としての `]` を読み飛ばした位置を返す。
     */
    private static function afterClassStart(string $body, int $i): int
    {
        if (($body[$i + 1] ?? '') === '^') {
            $i++;
        }

        return ($body[$i + 1] ?? '') === ']' ? $i + 1 : $i;
    }
}
