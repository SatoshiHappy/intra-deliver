<?php

namespace Tests\Architecture\Engine;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * 禁止トークン(抑制の注釈・属性・呼び出し・コマンドのオプション)の出現を走査する(汎用)。
 * 指摘は 規則 ID = トークン、file = 走査ルート起点の相対パス、行 = 出現した行。
 */
final class SourceTokens
{
    /**
     * PHP ファイルのコードとコメントからトークンを探す。文字列リテラルの中身はデータなので探さない
     * (注釈・属性・呼び出しは文字列の中では効かない。トークンの一覧を文字列で持つ規則や fixture の生成を誤検出しないため)。
     *
     * @param  list<string>  $paths  走査ルート $root 起点のパス。ディレクトリは配下の *.php を再帰的に走査し、
     *                               ファイルは拡張子を問わず走査する(拡張子の無い PHP の実行ファイル = artisan など)。`.` は走査ルートそのもの。
     *                               ディレクトリの中の `.` で始まるファイル・ディレクトリには入らない(phpstan のファイルの探し方と同じ)
     * @param  list<string>  $tokens
     * @param  list<string>  $excluded  走査から外すパスパターン(走査ルート起点。{@see Paths::matches()} の書き方)。
     *                                  末尾が `/**` のパターンに一致するディレクトリは中に入らない(依存物の巨大なディレクトリを辿らない)
     * @return list<Finding>
     *
     * @throws InvalidArgumentException パスが無いとき(対象の不在を対象 0 件にしない)
     */
    public static function inPhpCode(string $root, array $paths, array $tokens, array $excluded = []): array
    {
        $findings = [];

        foreach ($paths as $path) {
            foreach (self::phpFiles($root, $path, $excluded) as $file) {
                $text = self::blankStringLiterals(self::read($root.'/'.$file));
                array_push($findings, ...self::occurrences($text, $tokens, $file));
            }
        }

        return $findings;
    }

    /**
     * ファイルの全文(文字列を含む)からトークンを探す。コマンドのオプションのように、文字列として書かれるものに使う。
     *
     * @param  list<string>  $files  走査ルート $root 起点のファイル
     * @param  list<string>  $tokens
     * @return list<Finding>
     */
    public static function inText(string $root, array $files, array $tokens): array
    {
        $findings = [];

        foreach ($files as $file) {
            array_push($findings, ...self::occurrences(self::read($root.'/'.$file), $tokens, $file));
        }

        return $findings;
    }

    /**
     * 走査ルート $root の配下で glob $patterns({@see Paths::matchesGlob()})のどれかに一致するファイル(走査ルート起点。名前順)。
     * `.` で始まるファイル・ディレクトリには入らない。
     *
     * @param  list<string>  $patterns
     * @param  list<string>  $excluded  走査から外すパスパターン({@see self::inPhpCode()} と同じ)
     * @return list<string>
     */
    public static function globFiles(string $root, array $patterns, array $excluded = []): array
    {
        $files = array_values(array_filter(
            self::filesUnder($root, $root, $excluded),
            fn (string $file): bool => ! self::isExcluded($file, $excluded)
                && array_any($patterns, fn (string $pattern): bool => Paths::matchesGlob($pattern, $file)),
        ));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * シェルのスクリプトの、コメントでない行(先頭の空白の後が `#` でない行)。code は引用符('…'・"…")の中を空白にした行
     * (表示の文言の中の語を、コマンドとして数えないため)。
     *
     * @param  list<string>  $files  走査ルート $root 起点のファイル
     * @return list<array{file: string, line: int, text: string, code: string}>
     */
    public static function shellLines(string $root, array $files): array
    {
        $lines = [];

        foreach ($files as $file) {
            foreach (explode("\n", self::read($root.'/'.$file)) as $index => $text) {
                if (! str_starts_with(ltrim($text), '#')) {
                    $lines[] = ['file' => $file, 'line' => $index + 1, 'text' => rtrim($text), 'code' => self::withoutQuoted($text)];
                }
            }
        }

        return $lines;
    }

    /**
     * 引用符('…'・"…")の中を空白にした文字列(表示の文言の中の語を、コマンドとして数えないため)。長さは変えない。
     */
    public static function withoutQuoted(string $text): string
    {
        return (string) preg_replace_callback('/"[^"]*"|\'[^\']*\'/', fn (array $quoted): string => str_repeat(' ', strlen($quoted[0])), $text);
    }

    /**
     * ファイルの全文から、オプション $option(`--name value` か `--name=value`)の値を出現順に探す。値の引用符は外す。
     *
     * @param  list<string>  $files  走査ルート $root 起点のファイル
     * @return list<array{file: string, line: int, value: string}>
     */
    public static function optionValues(string $root, array $files, string $option): array
    {
        $values = [];
        $pattern = '/'.preg_quote($option, '/').'(?:=|[ \t]+)(\S+)/';

        foreach ($files as $file) {
            $text = self::read($root.'/'.$file);
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as [$value, $offset]) {
                $values[] = ['file' => $file, 'line' => self::lineAt($text, $offset), 'value' => trim($value, '"\'')];
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $excluded
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function phpFiles(string $root, string $path, array $excluded): array
    {
        $relative = Paths::normalize($path);
        $absolute = $relative === '' ? $root : $root.'/'.$relative;

        if (is_file($absolute)) {
            return self::isExcluded($relative, $excluded) ? [] : [$relative];
        }

        if (! is_dir($absolute)) {
            throw new InvalidArgumentException("走査するパスが無い: {$absolute}");
        }

        $files = array_values(array_filter(
            self::filesUnder($root, $absolute, $excluded),
            fn (string $file): bool => str_ends_with($file, '.php') && ! self::isExcluded($file, $excluded),
        ));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * ディレクトリ $absolute の配下のファイル(走査ルート起点)。`.` で始まるものと、除外で配下がすべて外れるディレクトリには入らない。
     *
     * @param  list<string>  $excluded
     * @return list<string>
     */
    private static function filesUnder(string $root, string $absolute, array $excluded): array
    {
        $directories = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $entry): bool => ! str_starts_with($entry->getFilename(), '.')
                && (! $entry->isDir() || ! self::isPrunedDirectory(self::relativeTo($root, $entry), $excluded)),
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

    private static function relativeTo(string $root, SplFileInfo $entry): string
    {
        return Paths::normalize(substr($entry->getPathname(), strlen($root) + 1));
    }

    /**
     * @param  list<string>  $excluded
     */
    private static function isExcluded(string $file, array $excluded): bool
    {
        foreach ($excluded as $pattern) {
            if (Paths::matches($pattern, $file)) {
                return true;
            }
        }

        return false;
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

    /**
     * 文字列リテラルの中身を、行の数を変えずに空白で置き換える。
     */
    private static function blankStringLiterals(string $source): string
    {
        $text = '';

        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $text .= $token;

                continue;
            }

            $text .= in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                ? (string) preg_replace('/[^\n]/', ' ', $token[1])
                : $token[1];
        }

        return $text;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<Finding>
     */
    private static function occurrences(string $text, array $tokens, string $file): array
    {
        $findings = [];

        foreach ($tokens as $token) {
            $offset = strpos($text, $token);

            while ($offset !== false) {
                $findings[] = new Finding($token, $file, self::lineAt($text, $offset));
                $offset = strpos($text, $token, $offset + strlen($token));
            }
        }

        return $findings;
    }

    private static function lineAt(string $text, int $offset): int
    {
        return substr_count($text, "\n", 0, $offset) + 1;
    }

    /**
     * @throws UnexpectedValueException
     */
    private static function read(string $path): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new UnexpectedValueException("ファイルを読めない: {$path}");
        }

        return $content;
    }
}
