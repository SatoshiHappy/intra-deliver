<?php

namespace Tests\Architecture\Engine;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * 自己証明の一時の木(汎用)= ディレクトリの写しと、その中に置いた、木の外のディレクトリを指すリンク。
 * リンクの先のファイルを、木の中のファイルと同じ検査に掛けるかを確かめるために使う。
 */
final class LinkedTree
{
    /**
     * $under の下に一意な名前のディレクトリを作り、$source の中身を写し、その中の $link(木の根起点)に $target(絶対パス)を指すリンクを置く。
     *
     * @throws RuntimeException 写せない・リンクを作れないとき
     */
    public static function create(string $under, string $prefix, string $source, string $link, string $target): string
    {
        $root = TempDirectory::create($under, $prefix);
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $copy = $root.'/'.substr($entry->getPathname(), strlen($source) + 1);
            $made = $entry->isDir() ? (is_dir($copy) || mkdir($copy, 0777, true)) : copy($entry->getPathname(), $copy);

            if (! $made) {
                throw new RuntimeException("一時の木に写せない: {$copy}");
            }
        }

        if (! is_dir(dirname($root.'/'.$link)) && ! mkdir(dirname($root.'/'.$link), 0777, true)) {
            throw new RuntimeException("リンクの置き場を作れない: {$link}");
        }

        if (! symlink($target, $root.'/'.$link)) {
            throw new RuntimeException("リンクを作れない: {$link} → {$target}");
        }

        return $root;
    }

    /**
     * 一時の木を消す。先にリンク $links(木の根起点)を外す(リンクの先のファイルを消さない)。
     *
     * @param  list<string>  $links
     */
    public static function remove(string $root, array $links): void
    {
        foreach ($links as $link) {
            if (is_link($root.'/'.$link)) {
                unlink($root.'/'.$link);
            }
        }

        TempDirectory::remove($root);
    }
}
