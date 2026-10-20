<?php

namespace Tests\Architecture\Engine;

use InvalidArgumentException;

/**
 * パスとパスパターンの扱い(汎用)。区切り文字は / にそろえる。
 *
 * パターンの書き方 = 台帳の書き方(`*` は 1 階層の中、`**` は配下すべて、`?` は 1 階層の中の 1 文字)。
 */
final class Paths
{
    /**
     * パス $path がパターン $pattern に一致するか。両方を {@see self::normalize()} してから比べる。
     */
    public static function matches(string $pattern, string $path): bool
    {
        return preg_match(self::toRegex(self::normalize($pattern)), self::normalize($path)) === 1;
    }

    /**
     * パス $path が、ファイルを探す glob $pattern に一致するか。台帳の書き方({@see self::matches()})と違い、`**` とその後の区切りの組は
     * 0 階層以上に一致する(Vitest の include と同じ。`a` の直下のファイルにも、配下のファイルにも一致する)。
     */
    public static function matchesGlob(string $pattern, string $path): bool
    {
        return preg_match(self::toRegex(self::normalize($pattern), true), self::normalize($path)) === 1;
    }

    /**
     * パスを正規化する: 区切りを / に、`.` と空の階層を除き、`x/..` を畳む(先頭の `..` は残す)、末尾の / を除く。
     */
    public static function normalize(string $path): string
    {
        $unified = str_replace('\\', '/', $path);
        $segments = [];

        foreach (explode('/', $unified) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return (str_starts_with($unified, '/') ? '/' : '').implode('/', $segments);
    }

    /**
     * 絶対パス $from から絶対パス $to への相対パス(必要なら `..` で遡る)。同じなら空文字。
     *
     * @throws InvalidArgumentException どちらかが絶対パスでないとき
     */
    public static function relative(string $from, string $to): string
    {
        $fromSegments = self::absoluteSegments($from);
        $toSegments = self::absoluteSegments($to);
        $common = 0;

        while (isset($fromSegments[$common], $toSegments[$common]) && $fromSegments[$common] === $toSegments[$common]) {
            $common++;
        }

        $up = array_fill(0, count($fromSegments) - $common, '..');

        return implode('/', [...$up, ...array_slice($toSegments, $common)]);
    }

    /**
     * 絶対パスのディレクトリ $directory を含む git の作業木の根(.git がある最も近い祖先)を返す。
     *
     * @throws InvalidArgumentException 作業木の中でないとき
     */
    public static function repositoryRoot(string $directory): string
    {
        $current = self::normalize($directory);

        while (! file_exists($current.'/.git')) {
            $parent = dirname($current);

            if ($parent === $current) {
                throw new InvalidArgumentException("git の作業木の中ではない: {$directory}");
            }

            $current = $parent;
        }

        return $current;
    }

    /**
     * 作業木の根から絶対パスのディレクトリ $directory への相対パス(根そのものなら空文字)。
     *
     * @throws InvalidArgumentException 作業木の中でないとき
     */
    public static function repositoryPrefix(string $directory): string
    {
        return self::relative(self::repositoryRoot($directory), $directory);
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function absoluteSegments(string $path): array
    {
        $normalized = self::normalize($path);

        if (! str_starts_with($normalized, '/')) {
            throw new InvalidArgumentException("絶対パスではない: {$path}");
        }

        return array_values(array_filter(explode('/', $normalized), fn (string $segment): bool => $segment !== ''));
    }

    private static function toRegex(string $pattern, bool $globstarMatchesNone = false): string
    {
        $delimiters = $globstarMatchesNone ? '/(\*\*\/|\*\*|\*|\?)/' : '/(\*\*|\*|\?)/';
        $parts = preg_split($delimiters, $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            throw new InvalidArgumentException("パターンを分けられない: {$pattern}");
        }

        $regex = '';

        foreach ($parts as $part) {
            $regex .= match ($part) {
                '**/' => '(?:.*/)?',
                '**' => '.*',
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($part, '#'),
            };
        }

        return '#^'.$regex.'$#u';
    }
}
