<?php

namespace Tests\Architecture\Engine;

use DOMElement;
use DOMNode;
use DOMXPath;
use UnexpectedValueException;

/**
 * PHPUnit の設定ファイル(phpunit.xml)を読む(汎用)。パスは設定ファイルのディレクトリ起点のまま返す。
 */
final readonly class PhpUnitConfig
{
    private function __construct(
        private DOMXPath $xpath,
        private string $directory,
    ) {}

    public static function load(string $path): self
    {
        return new self(XmlFile::xpath($path), dirname($path));
    }

    /**
     * 定義された suite の名前(文書順)。
     *
     * @return list<string>
     */
    public function suiteNames(): array
    {
        return XmlFile::values($this->xpath, '/phpunit/testsuites/testsuite/@name');
    }

    /**
     * suite 名 → その suite が走査するディレクトリ(正規化したパス。文書順)。
     * directory 要素に属性(suffix・prefix・groups・phpVersion など、走査を絞るもの)があれば、パスの後ろに ` [名前=値 …]` を付けて表す。
     *
     * @return array<string, list<string>>
     */
    public function suiteDirectories(): array
    {
        $directories = [];

        foreach ($this->elements('/phpunit/testsuites/testsuite') as $suite) {
            $name = $suite->getAttribute('name');
            $directories[$name] ??= [];

            foreach ($this->elements('directory', $suite) as $directory) {
                $directories[$name][] = Paths::normalize(trim($directory->textContent)).self::describeAttributes($directory);
            }
        }

        return $directories;
    }

    /**
     * suite 名 → その suite が走査するディレクトリのパスだけ(正規化したパス。文書順)。suffix・prefix などの絞り込みは返さない
     * (junit の testcase がどの suite に属するかを、ディレクトリの下にあるかだけで決めるため)。
     *
     * @return array<string, list<string>>
     */
    public function suitePaths(): array
    {
        $paths = [];

        foreach ($this->elements('/phpunit/testsuites/testsuite') as $suite) {
            $name = $suite->getAttribute('name');
            $paths[$name] ??= [];

            foreach ($this->elements('directory', $suite) as $directory) {
                $paths[$name][] = Paths::normalize(trim($directory->textContent));
            }
        }

        return $paths;
    }

    /**
     * junit の出力先(設定ファイルのディレクトリ起点)。書かれていなければ null。
     */
    public function junitFile(): ?string
    {
        return XmlFile::values($this->xpath, '/phpunit/logging/junit/@outputFile')[0] ?? null;
    }

    /**
     * `<php>` の env と server に書かれた変数 $name の値。
     *
     * @return list<string>
     */
    public function environmentValues(string $name): array
    {
        return XmlFile::values($this->xpath, "/phpunit/php/*[self::env or self::server][@name='{$name}']/@value");
    }

    /**
     * テストと対象を絞る記述の一覧(key = 記述の場所、kind = 台帳の種類、value = 除外したパスか group 名)。
     * ディレクトリの除外は `<dir>/**` に正規化する(台帳のパスパターンと同じ書き方)。
     *
     * @return list<array{key: string, kind: string, value: string}>
     */
    public function exclusions(): array
    {
        $exclusions = [];
        $sources = [
            ['testsuite.exclude', '/phpunit/testsuites/testsuite/exclude', null],
            ['groups.include', '/phpunit/groups/include/group', false],
            ['groups.exclude', '/phpunit/groups/exclude/group', false],
            ['source.exclude', '/phpunit/source/exclude/directory', true],
            ['source.exclude', '/phpunit/source/exclude/file', null],
        ];

        foreach ($sources as [$key, $expression, $isDirectory]) {
            foreach (XmlFile::values($this->xpath, $expression) as $value) {
                $exclusions[] = [
                    'key' => $key,
                    'kind' => $isDirectory === false ? 'rule' : 'path',
                    'value' => $this->normalize($value, $isDirectory),
                ];
            }
        }

        return $exclusions;
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(string $expression, ?DOMNode $context = null): array
    {
        $nodes = $this->xpath->query($expression, $context);

        if ($nodes === false) {
            throw new UnexpectedValueException("XPath を評価できない: {$expression}");
        }

        $elements = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function describeAttributes(DOMElement $element): string
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->nodeName.'='.$attribute->nodeValue;
        }

        return $attributes === [] ? '' : ' ['.implode(' ', $attributes).']';
    }

    /**
     * @param  bool|null  $isDirectory  true = ディレクトリ / false = パスでない(group 名)/ null = 実物で判定する
     */
    private function normalize(string $value, ?bool $isDirectory): string
    {
        if ($isDirectory === false) {
            return $value;
        }

        $path = Paths::normalize($value);
        $directory = $isDirectory ?? is_dir($this->directory.'/'.$path);

        return $directory ? $path.'/**' : $path;
    }
}
