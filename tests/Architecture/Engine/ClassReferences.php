<?php

namespace Tests\Architecture\Engine;

use PhpToken;

/**
 * PHP のソースが名指すクラスの名前を、名前空間と `use` で完全修飾に解決する(汎用。PHP のトークンで読む)。
 *
 * 拾う所 = `use` の取り込み(別名・まとめた形を含む)・コードの完全修飾と修飾された名前・PHPDoc の型
 * ({@see self::DOC_TAGS} の型と総称の型引数)の名前・クラス名をそのまま書いた文字列リテラル。
 * 拾わない所 = 修飾の無いコードの名前(取り込んだ名前か同じ名前空間なので、取り込みの側で拾う)・名前を組み立てた文字列・
 * インスタンスのメソッド呼び出しの先。名前がクラスとして在るかは確かめない(呼び手が決める)。
 */
final class ClassReferences
{
    private const DOC_TAGS = 'param|return|var|property|property-read|property-write|method|throws|template';

    private const NAME = '\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*';

    private const STRING_NAME = '/\A\\\\?[A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+\z/';

    /** 型の式の括弧(開く = 1・閉じる = -1) */
    private const BRACKETS = ['<' => 1, '(' => 1, '{' => 1, '[' => 1, '>' => -1, ')' => -1, '}' => -1, ']' => -1];

    private string $namespace = '';

    /** @var array<string, string> 小文字の別名 → 完全修飾 */
    private array $aliases = [];

    /** @var list<string> */
    private array $names = [];

    /**
     * @param  list<PhpToken>  $tokens  空白と通常のコメントを除いたトークン
     */
    private function __construct(private readonly array $tokens) {}

    /**
     * ソース $source の名前空間と、名指すクラスの名前(完全修飾。先頭の `\` なし。重複なし・名前順)。
     *
     * @return array{namespace: string, names: list<string>}
     */
    public static function in(string $source): array
    {
        $reader = new self(array_values(array_filter(
            PhpToken::tokenize($source),
            fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT]),
        )));
        $depth = 0;

        for ($index = 0; isset($reader->tokens[$index]); $index++) {
            $token = $reader->tokens[$index];
            $index = $token->is(T_USE) && $depth === 0 ? $reader->readImports($index) : $reader->read($index);
            $depth += match (true) {
                $token->text === '{', $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) => 1,
                $token->text === '}' => -1,
                default => 0,
            };
        }

        $names = array_values(array_unique($reader->names));
        sort($names, SORT_STRING);

        return ['namespace' => $reader->namespace, 'names' => $names];
    }

    /**
     * 位置 $index のトークン 1 つを読む。戻り値 = 読み終えた位置。
     */
    private function read(int $index): int
    {
        $token = $this->tokens[$index];
        $previous = $this->tokens[$index - 1] ?? null;

        if ($token->is([T_NAME_QUALIFIED, T_STRING]) && $previous?->is(T_NAMESPACE) === true) {
            $this->namespace = $token->text;
        } elseif ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE])) {
            $this->names[] = $this->resolve($token->text);
        } elseif ($token->is(T_DOC_COMMENT)) {
            array_push($this->names, ...array_map($this->resolve(...), self::docNames($token->text)));
        } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $text = str_replace('\\\\', '\\', substr($token->text, 1, -1));
            if (preg_match(self::STRING_NAME, $text) === 1) {
                $this->names[] = ltrim($text, '\\');
            }
        }

        return $index;
    }

    /**
     * 位置 $index の `use` から `;` までの取り込みを読む(関数・定数の取り込みと、無名関数の `use (…)` は除く)。戻り値 = `;` の手前の位置。
     */
    private function readImports(int $index): int
    {
        $statement = '';

        for ($cursor = $index + 1; isset($this->tokens[$cursor]) && $this->tokens[$cursor]->text !== ';'; $cursor++) {
            $statement .= ' '.$this->tokens[$cursor]->text;
        }

        if (preg_match('/\A\s*(?:function\s|const\s|\()/i', $statement) !== 1) {
            foreach (self::imports($statement) as $alias => $name) {
                $this->aliases[strtolower($alias)] = $name;
                $this->names[] = $name;
            }
        }

        return $cursor - 1;
    }

    /**
     * 名前 $name を、名前空間と取り込みで完全修飾に解決する。
     */
    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return ltrim($this->namespace.'\\'.substr($name, strlen('namespace\\')), '\\');
        }

        [$first] = explode('\\', $name);
        $alias = $this->aliases[strtolower($first)] ?? null;

        return $alias !== null ? $alias.substr($name, strlen($first)) : ltrim($this->namespace.'\\'.$name, '\\');
    }

    /**
     * `use` の文 $statement(トークンを空白でつないだもの)の取り込み(別名 → 完全修飾。まとめた形 `A\{B, C as D}` を含む)。
     *
     * @return array<string, string>
     */
    private static function imports(string $statement): array
    {
        $imports = [];
        $prefix = '';
        $clauses = $statement;

        if (preg_match('/\A\s*(\S+?)\s*\\\\?\s*\{(.*)\}\s*\z/s', $statement, $match) === 1) {
            $prefix = trim($match[1], ' \\').'\\';
            $clauses = $match[2];
        }

        foreach (explode(',', $clauses) as $clause) {
            $parts = preg_split('/\s+as\s+/i', trim($clause)) ?: [];
            $name = ltrim(str_replace(' ', '', $parts[0] ?? ''), '\\');

            if ($name !== '') {
                $imports[trim($parts[1] ?? self::lastSegment($name))] = ltrim($prefix.$name, '\\');
            }
        }

        return $imports;
    }

    /**
     * PHPDoc $doc の型({@see self::DOC_TAGS})に出てくる名前(書いたままの形)。
     *
     * @return list<string>
     */
    private static function docNames(string $doc): array
    {
        $names = [];
        preg_match_all('/@(?:'.self::DOC_TAGS.')(?![A-Za-z-])[ \t]+([^\n]*)/', $doc, $tags, PREG_SET_ORDER);

        foreach ($tags as [$tag, $rest]) {
            $type = match (true) {
                str_starts_with($tag, '@method') => $rest,
                str_starts_with($tag, '@template') => self::leadingType(self::templateBound($rest)),
                default => self::leadingType($rest),
            };
            preg_match_all('/(?<![A-Za-z0-9_\\\\$-])'.self::NAME.'/', $type, $matches);
            array_push($names, ...$matches[0]);
        }

        return $names;
    }

    /**
     * タグの後ろ $rest の先頭の型の式(括弧の中の空白と、`|`・`&`・`:` の前後の空白を含む)。
     */
    private static function leadingType(string $rest): string
    {
        $depth = 0;

        foreach (str_split($rest) as $position => $character) {
            $depth += self::BRACKETS[$character] ?? 0;

            if ($depth <= 0 && self::endsType($rest, $position)) {
                return substr($rest, 0, $position);
            }
        }

        return $rest;
    }

    /**
     * 位置 $position が型の式の終わりの空白か(前後が `|`・`&`・`:` の空白は型の続き)。
     */
    private static function endsType(string $rest, int $position): bool
    {
        if (! in_array($rest[$position], [' ', "\t"], true)) {
            return false;
        }

        $before = rtrim(substr($rest, 0, $position));
        $after = ltrim(substr($rest, $position));

        if ($before === '' || $after === '') {
            return true;
        }

        return ! str_contains('|&:', $before[-1]) && ! str_contains('|&:', $after[0]);
    }

    /**
     * `@template` のタグの後ろ $rest(`T of 型`)の型。上限の型が無ければ空。
     */
    private static function templateBound(string $rest): string
    {
        return preg_match('/\A\S+\s+of\s+(.*)\z/s', $rest, $bound) === 1 ? $bound[1] : '';
    }

    private static function lastSegment(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
