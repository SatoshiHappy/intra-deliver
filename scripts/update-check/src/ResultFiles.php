<?php

namespace UpdateCheck;

use RuntimeException;

/**
 * 結果のファイル(summary.md・comment.md)を置く。同じディレクトリの一時ファイルに書き、書けたバイト数が全文の長さと一致することを確かめてから置き換える。
 * 失敗したら、この組の一時ファイルと置き場のファイルを(前の回の残りも)消して投げる(不完全な結果、前の回との混在を残さない)。
 */
final class ResultFiles
{
    /**
     * @param  array<string, string>  $files  名前 → 全文(並びの順に置き換える)
     *
     * @throws RuntimeException
     */
    public static function write(string $dir, array $files): void
    {
        error_clear_last();

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("結果のディレクトリを作れない: {$dir}".self::cause());
        }

        try {
            self::put($dir, $files);
        } catch (RuntimeException $exception) {
            $leftovers = self::removeAll($dir, array_keys($files));

            throw new RuntimeException($exception->getMessage().($leftovers === [] ? '' : '(消せなかったパス: '.implode(', ', $leftovers).')'));
        }
    }

    /**
     * @param  array<string, string>  $files
     *
     * @throws RuntimeException
     */
    private static function put(string $dir, array $files): void
    {
        foreach ($files as $name => $contents) {
            $temporary = "{$dir}/{$name}.tmp";
            error_clear_last();

            if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
                throw new RuntimeException("結果のファイルを書き切れない: {$temporary}".self::cause());
            }
        }

        foreach (array_keys($files) as $name) {
            error_clear_last();

            if (! @rename("{$dir}/{$name}.tmp", "{$dir}/{$name}")) {
                throw new RuntimeException("結果のファイルを置き換えられない: {$dir}/{$name}".self::cause());
            }
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<string> 消せなかったパス
     */
    private static function removeAll(string $dir, array $names): array
    {
        $failed = [];

        foreach ($names as $name) {
            foreach (["{$dir}/{$name}.tmp", "{$dir}/{$name}"] as $path) {
                if (is_file($path) && ! @unlink($path)) {
                    $failed[] = $path;
                }
            }
        }

        return $failed;
    }

    private static function cause(): string
    {
        $message = error_get_last()['message'] ?? '';

        return $message === '' ? '' : "({$message})";
    }
}
