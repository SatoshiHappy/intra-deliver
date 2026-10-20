<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * composer.json の autoload・autoload-dev のうち、クラスの走査の範囲を決める設定を読む(汎用)。
 *
 * - exclude-from-classmap = 走査から外すパス。composer は前後の / を外し、そのパスと配下を外す
 *   (公式文書 04-schema の「Exclude files from classmaps」)。台帳のパスパターンの書き方では、照らす木でディレクトリか
 *   ワイルドカードを含むなら `<パス>/**`、それ以外(ファイル・木に無いパス)はパスそのもの
 * - psr-4・psr-0 = 名前空間の対応を確かめながら走査するディレクトリ(`--strict-psr` が違反を数える範囲)
 */
final class ComposerAutoload
{
    public const EXCLUDE_KEY = 'exclude-from-classmap';

    private const SECTIONS = ['autoload', 'autoload-dev'];

    private const PSR_STANDARDS = ['psr-4', 'psr-0'];

    /**
     * @param  array<mixed>  $manifest
     * @param  string  $root  composer.json のある木の根(値がディレクトリかを見る)
     * @return list<array{key: string, kind: string, value: string}>
     *
     * @throws UnexpectedValueException 形が違うとき
     */
    public static function classmapExclusions(array $manifest, string $root): array
    {
        $exclusions = [];

        foreach (self::SECTIONS as $section) {
            $autoload = self::section($manifest, $section);

            foreach (Values::optionalStrings($autoload, self::EXCLUDE_KEY, "composer.json の {$section}") as $value) {
                $path = Paths::normalize(trim(str_replace('\\', '/', $value), '/'));
                $pattern = strpbrk($path, '*?') !== false || is_dir($root.'/'.$path) ? $path.'/**' : $path;
                $exclusions[] = ['key' => self::EXCLUDE_KEY, 'kind' => 'path', 'value' => $pattern];
            }
        }

        return $exclusions;
    }

    /**
     * パスパターン $patterns のうち、psr-4・psr-0 のディレクトリの走査に掛かるもの(正規化して、登録の順)。
     * 掛かる = パターンのワイルドカードより前の固定の部分とディレクトリの、どちらかがもう一方の配下か同じ
     * (composer が走査のディレクトリごとに除外を選ぶのと同じ判定。空のディレクトリ = アプリのルートで、すべてに掛かる)。
     *
     * @param  list<string>  $patterns
     * @param  array<mixed>  $manifest
     * @return list<string>
     *
     * @throws UnexpectedValueException 形が違うとき
     */
    public static function overlappingPsrDirectories(array $patterns, array $manifest): array
    {
        $directories = array_map(self::directoryPrefix(...), self::psrDirectories($manifest));
        $normalized = array_map(Paths::normalize(...), $patterns);

        return array_values(array_filter($normalized, function (string $pattern) use ($directories): bool {
            $prefix = substr($pattern.'/', 0, strcspn($pattern.'/', '*?'));

            foreach ($directories as $directory) {
                if (str_starts_with($prefix, $directory) || str_starts_with($directory, $prefix)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * ディレクトリの配下のパスが共通に持つ先頭の部分(アプリのルート = 空)。
     */
    private static function directoryPrefix(string $directory): string
    {
        $normalized = Paths::normalize($directory);

        return $normalized === '' ? '' : $normalized.'/';
    }

    /**
     * @param  array<mixed>  $manifest
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    private static function psrDirectories(array $manifest): array
    {
        $directories = [];

        foreach (self::SECTIONS as $section) {
            $autoload = self::section($manifest, $section);

            foreach (self::PSR_STANDARDS as $standard) {
                $label = "composer.json の {$section} の {$standard}";
                array_push($directories, ...self::mappedDirectories($autoload, $standard, $label));
            }
        }

        return $directories;
    }

    /**
     * 名前空間 → ディレクトリ(1 つの文字列か一覧)の対応のディレクトリ。
     *
     * @param  array<mixed>  $autoload
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    private static function mappedDirectories(array $autoload, string $standard, string $label): array
    {
        $directories = [];

        foreach (Values::table($autoload[$standard] ?? [], $label) as $paths) {
            array_push($directories, ...Values::strings(is_string($paths) ? [$paths] : $paths, $label));
        }

        return $directories;
    }

    /**
     * @param  array<mixed>  $manifest
     * @return array<mixed>
     *
     * @throws UnexpectedValueException
     */
    private static function section(array $manifest, string $section): array
    {
        return Values::table($manifest[$section] ?? [], "composer.json の {$section}");
    }
}
