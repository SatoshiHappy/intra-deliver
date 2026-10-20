<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * git が無視しないファイルの一覧(汎用)。
 */
final class TrackedFiles
{
    /**
     * $root の下の、git が追跡しているファイルと、まだ追加していない無視されないファイル($root 起点のパス。消したファイルは除く)。
     *
     * @return list<string>
     *
     * @throws UnexpectedValueException git が失敗したとき
     */
    public static function notIgnored(string $root): array
    {
        $result = Process::run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], $root);

        if ($result->exitCode !== 0) {
            throw new UnexpectedValueException("git でファイルの一覧を得られない\n".$result->describe());
        }

        return array_values(array_filter(
            array_unique(explode("\0", $result->stdout)),
            fn (string $path): bool => $path !== '' && is_file($root.'/'.$path),
        ));
    }

    /**
     * 根 $root からの相対パス $file のファイルの中身。
     *
     * @throws UnexpectedValueException 読めないとき
     */
    public static function contents(string $root, string $file): string
    {
        $content = is_file($root.'/'.$file) ? file_get_contents($root.'/'.$file) : false;

        if ($content === false) {
            throw new UnexpectedValueException("ファイルを読めない: {$file}");
        }

        return $content;
    }

    /**
     * 検査 $check の対象 = 根 $root の git が無視しないファイル(呼び手が既に持っていれば $files)から、台帳 $ledger の $check の path を除いたもの。
     *
     * @param  list<string>|null  $files
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    public static function targets(string $root, ExclusionLedger $ledger, string $check, ?array $files = null): array
    {
        return self::without($files ?? self::notIgnored($root), $ledger->paths([$check]));
    }

    /**
     * ファイル $files のうち、パスパターン $excluded({@see Paths::matches()})のどれにも一致しないもの。
     *
     * @param  list<string>  $files
     * @param  list<string>  $excluded
     * @return list<string>
     */
    public static function without(array $files, array $excluded): array
    {
        $isExcluded = fn (string $file): bool => array_any(
            $excluded,
            fn (string $pattern): bool => Paths::matches($pattern, $file),
        );

        return array_values(array_filter($files, fn (string $file): bool => ! $isExcluded($file)));
    }

    /**
     * ファイル $files のうち、拡張子が $extensions(`.` を含まない)のどれかのもの。
     *
     * @param  list<string>  $files
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public static function withExtensions(array $files, array $extensions): array
    {
        return array_values(array_filter(
            $files,
            fn (string $file): bool => in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions, true),
        ));
    }
}
