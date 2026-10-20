<?php

namespace Tests\Architecture\Engine;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * 文書の木(docs のリポジトリの作業木)のファイルと、文書の見出しの節の番号と本文(汎用)。`.` で始まる名前は読まない。
 */
final class DocsTree
{
    private readonly string $root;

    /** @var array<string, true> 根からの相対パス → true */
    private readonly array $files;

    /** @var array<string, string> 読んだ文書の本文 */
    private array $bodies = [];

    /**
     * @throws UnexpectedValueException $root がディレクトリでないとき
     */
    public function __construct(string $root)
    {
        $absolute = realpath($root);

        if ($absolute === false || ! is_dir($absolute)) {
            throw new UnexpectedValueException("文書の木が無い: {$root}");
        }

        $this->root = $absolute;
        $this->files = array_fill_keys(self::filesUnder($absolute), true);
    }

    /**
     * 根の直下のディレクトリの名前。
     *
     * @return list<string>
     */
    public function topLevelDirectories(): array
    {
        $nested = array_filter(array_keys($this->files), fn (string $path): bool => str_contains($path, '/'));

        return array_values(array_unique(array_map(fn (string $path): string => explode('/', $path)[0], $nested)));
    }

    public function has(string $path): bool
    {
        return isset($this->files[$path]);
    }

    /**
     * パスが $suffix と同じか、`/<$suffix>` で終わるファイル。ディレクトリ $excludedDirectory(根の直下)の下は除く。
     *
     * @return list<string>
     */
    public function filesEndingWith(string $suffix, string $excludedDirectory): array
    {
        return array_values(array_filter(
            array_keys($this->files),
            fn (string $path): bool => ! str_starts_with($path, $excludedDirectory.'/')
                && ($path === $suffix || str_ends_with($path, '/'.$suffix)),
        ));
    }

    /**
     * 文書 $path の見出し(行頭の `#` の続きの後)に、段の深さを問わず節の番号 $number(`N`・`N.M`・`N.M.K`)があるか。
     */
    public function hasSection(string $path, string $number): bool
    {
        return self::bodyHasSection($this->body($path), $number);
    }

    /**
     * 本文 $body の見出しに、段の深さを問わず節の番号 $number があるか({@see hasSection} の判定。木の外の文書にも使う)。
     */
    public static function bodyHasSection(string $body, string $number): bool
    {
        $quoted = preg_quote($number, '/');

        return preg_match('/^#+[ \t]+'.$quoted.'\.?(?=\s|\z)/mu', $body) === 1;
    }

    /**
     * 文書 $path の本文のどこかに $text があるか。
     */
    public function contains(string $path, string $text): bool
    {
        return str_contains($this->body($path), $text);
    }

    private function body(string $path): string
    {
        return $this->bodies[$path] ??= (string) file_get_contents($this->root.'/'.$path);
    }

    /**
     * @return list<string>
     */
    private static function filesUnder(string $root): array
    {
        $directories = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directories,
            fn (SplFileInfo $entry): bool => ! str_starts_with($entry->getFilename(), '.'),
        );
        $files = [];

        foreach (new RecursiveIteratorIterator($filter) as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                $files[] = Paths::relative($root, $entry->getPathname());
            }
        }

        return $files;
    }
}
