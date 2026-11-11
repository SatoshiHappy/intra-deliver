<?php

namespace Tests\Architecture\Engine;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * 走査の木の歩き方(汎用。{@see SourceTokens} が使う)。道は走査ルート起点。
 */
final class SourceTree
{
    /**
     * ディレクトリ $absolute の配下のファイル(走査ルート起点)。`.` で始まるものと、除外で配下がすべて外れるディレクトリには入らない。
     * ディレクトリのリンクは一律にはたどらない(循環を作らない)。呼び手が渡すリンク $links のうち $absolute の下に在るものだけを足し、
     * ほかのディレクトリのリンクは除外の外なら落とす。ファイルのリンクは phpstan と同じに読む。
     *
     * @param  list<string>  $excluded
     * @param  list<string>  $links
     * @return list<string>
     *
     * @throws UnexpectedValueException 一覧の外のディレクトリのリンクがあるとき
     */
    public static function files(string $root, string $absolute, array $excluded, array $links): array
    {
        $base = $absolute === $root ? '' : substr($absolute, strlen($root) + 1).'/';
        $files = self::walk($root, $absolute, $excluded, $links);

        foreach ($links as $link) {
            $relative = Paths::normalize($link);
            $path = $root.'/'.$relative;

            $listed = str_starts_with($relative, $base) && is_link($path) && is_dir($path);

            if ($listed && ! self::isPrunedDirectory($relative, $excluded)) {
                $files = [...$files, ...self::walk($root, $path, $excluded, [])];
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * ディレクトリ $absolute の配下のファイル(リンクをたどらない)。たどるリンク $links はここでは入らず、ほかのディレクトリのリンクは落とす。
     *
     * @param  list<string>  $excluded
     * @param  list<string>  $links
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    private static function walk(string $root, string $absolute, array $excluded, array $links): array
    {
        $directories = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $entry): bool => ! str_starts_with($entry->getFilename(), '.')
                && (! $entry->isDir()
                    || self::entersDirectory(self::relativeTo($root, $entry), $entry, $excluded, $links)),
        );
        $files = [];

        /** @var SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator($directories) as $entry) {
            if ($entry->isFile()) {
                $files[] = self::relativeTo($root, $entry);
            }
        }

        return $files;
    }

    /**
     * ディレクトリ(かディレクトリのリンク)$directory に走査が入るか。除外で配下がすべて外れるなら入らない。たどるリンクは呼び手が別に足す。
     *
     * @param  list<string>  $excluded
     * @param  list<string>  $links
     *
     * @throws UnexpectedValueException 一覧の外のディレクトリのリンクのとき
     */
    private static function entersDirectory(string $directory, SplFileInfo $entry, array $excluded, array $links): bool
    {
        if (self::isPrunedDirectory($directory, $excluded)) {
            return false;
        }

        if (! $entry->isLink()) {
            return true;
        }

        if (in_array($directory, array_map(Paths::normalize(...), $links), true)) {
            return false;
        }

        throw new UnexpectedValueException(
            "走査する木に、たどるリンクの一覧の外のディレクトリのリンクがある: {$directory}(たどるならリンクの一覧に足し、見ないなら除外に入れる)",
        );
    }

    private static function relativeTo(string $root, SplFileInfo $entry): string
    {
        return Paths::normalize(substr($entry->getPathname(), strlen($root) + 1));
    }

    /**
     * ディレクトリ $directory の配下がすべて除外されるか(末尾が `/**` のパターンの前半に一致する)。
     *
     * @param  list<string>  $excluded
     */
    private static function isPrunedDirectory(string $directory, array $excluded): bool
    {
        foreach ($excluded as $pattern) {
            $normalized = Paths::normalize($pattern);

            if (str_ends_with($normalized, '/**') && Paths::matches(substr($normalized, 0, -3), $directory)) {
                return true;
            }
        }

        return false;
    }
}
