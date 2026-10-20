<?php

namespace Tests\Architecture\Engine;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * ディレクトリの構造を読む(汎用)。
 */
final class Directories
{
    /**
     * ディレクトリの直下にあるディレクトリの名前を、名前順で返す(ファイルは含めない。隠しディレクトリは含める)。
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException ディレクトリでないとき
     * @throws UnexpectedValueException 読めないとき
     */
    public static function names(string $path): array
    {
        if (! is_dir($path)) {
            throw new InvalidArgumentException("ディレクトリではない: {$path}");
        }

        $entries = scandir($path);

        if ($entries === false) {
            throw new UnexpectedValueException("ディレクトリを読めない: {$path}");
        }

        $names = array_values(array_filter(
            $entries,
            fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($path.DIRECTORY_SEPARATOR.$entry),
        ));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * ディレクトリの直下にあるファイルの名前を、名前順で返す(ディレクトリは含めない)。
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException ディレクトリでないとき
     * @throws UnexpectedValueException 読めないとき
     */
    public static function fileNames(string $path): array
    {
        if (! is_dir($path)) {
            throw new InvalidArgumentException("ディレクトリではない: {$path}");
        }

        $entries = scandir($path);

        if ($entries === false) {
            throw new UnexpectedValueException("ディレクトリを読めない: {$path}");
        }

        $names = array_values(array_filter(
            $entries,
            fn (string $entry): bool => is_file($path.DIRECTORY_SEPARATOR.$entry),
        ));
        sort($names, SORT_STRING);

        return $names;
    }
}
