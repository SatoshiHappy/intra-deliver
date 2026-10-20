<?php

namespace Tests\Architecture\Engine;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 自己証明の fixture 用に一時ディレクトリを作り、テスト後に消す(汎用)。
 *
 * #7・#11 の fixture は通常の走査除外を掛けない検査に晒すため commit せず、
 * 検査の成果物と同じ置き場(config/guardrails.php の artifacts_dir)の下にテストのたびに作る。
 */
final class TempDirectory
{
    /**
     * $under の下に一意な名前のディレクトリを作り、パスを返す。
     */
    public static function create(string $under, string $prefix): string
    {
        $path = rtrim($under, '/').'/'.$prefix.'-'.bin2hex(random_bytes(8));
        mkdir($path, 0777, true);

        return $path;
    }

    /**
     * ディレクトリを中身ごと削除する(存在しなければ何もしない)。
     */
    public static function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
