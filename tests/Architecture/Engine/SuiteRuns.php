<?php

namespace Tests\Architecture\Engine;

use DOMElement;
use UnexpectedValueException;

/**
 * テストの suite ごとの実行記録を junit の XML から読み、要求と照合する(汎用)。
 *
 * suite の判定は junit の testsuite 要素の名前や入れ子に頼らず、各 testcase の file が属するディレクトリ(設定の suite が走査する
 * ディレクトリ。対象のルートからの相対パスの先頭一致)で数える。直列の phpunit と、paratest が各プロセスの記録を併合した junit では testsuite の入れ子の形が違うため。
 * 要求 = 各 suite に属する testcase が 1 件以上あり(= 実行された)、スキップ 0、エラーと失敗 0。
 */
final class SuiteRuns
{
    public const NOT_RUN = 'suite.notRun';

    public const SKIPPED = 'suite.skipped';

    public const FAILED = 'suite.failed';

    /**
     * junit の XML から suite 名 → 件数を読む。testcase が 1 件も無い suite は含めない。
     *
     * @param  array<string, list<string>>  $suitePaths  suite 名 → 走査するディレクトリ($root からの相対パス。`tests/Unit` など)
     * @param  string  $root  対象のルート(testcase の file をこの下の相対パスにして照合する)
     * @return array<string, array{tests: int, skipped: int, errors: int, failures: int}>
     *
     * @throws UnexpectedValueException 読めない・testcase に file が無い・ルートの外か、どの suite にも属さない testcase がある・
     *                                  最長一致の長さが同じ suite が 2 つ以上あるとき
     */
    public static function fromJunit(string $path, array $suitePaths, string $root): array
    {
        $nodes = XmlFile::xpath($path)->query('//testcase');

        if ($nodes === false) {
            throw new UnexpectedValueException("junit の testcase を読めない: {$path}");
        }

        $runs = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $suite = self::suiteOf($node, $suitePaths, $root, $path);
            $runs[$suite] ??= ['tests' => 0, 'skipped' => 0, 'errors' => 0, 'failures' => 0];
            $runs[$suite]['tests']++;
            $runs[$suite]['skipped'] += self::hasChild($node, 'skipped') ? 1 : 0;
            $runs[$suite]['errors'] += self::hasChild($node, 'error') ? 1 : 0;
            $runs[$suite]['failures'] += self::hasChild($node, 'failure') ? 1 : 0;
        }

        return $runs;
    }

    /**
     * 要求する suite の一覧 $suites と実行記録 $runs を照合し、満たさない suite ごとに指摘を返す(file = suite 名)。
     *
     * @param  list<string>  $suites
     * @param  array<string, array{tests: int, skipped: int, errors: int, failures: int}>  $runs
     * @return list<Finding>
     */
    public static function findings(array $suites, array $runs): array
    {
        $findings = [];

        foreach ($suites as $suite) {
            $run = $runs[$suite] ?? null;

            $ruleIds = match (true) {
                $run === null => [self::NOT_RUN],
                default => array_keys(array_filter([
                    self::SKIPPED => $run['skipped'] > 0,
                    self::FAILED => $run['errors'] + $run['failures'] > 0,
                ])),
            };

            foreach ($ruleIds as $ruleId) {
                $findings[] = new Finding($ruleId, $suite, null);
            }
        }

        return $findings;
    }

    /**
     * testcase の file($root からの相対パス)が属する suite の名前。ディレクトリの先頭一致のうち最長のものの suite。
     * 入れ子のディレクトリ(tests/Feature と tests/Feature/Api)は最長一致で決まる。最長の長さが同じ suite が 2 つ以上あれば決められない。
     *
     * @param  array<string, list<string>>  $suitePaths
     *
     * @throws UnexpectedValueException
     */
    private static function suiteOf(DOMElement $testcase, array $suitePaths, string $root, string $path): string
    {
        $file = $testcase->getAttribute('file');

        if ($file === '') {
            throw new UnexpectedValueException("junit の testcase {$testcase->getAttribute('name')} に file が無く、suite を決められない({$path})");
        }

        $prefix = rtrim($root, '/').'/';

        if (! str_starts_with($file, $prefix)) {
            throw new UnexpectedValueException("junit の testcase の file が対象のルート({$root})の外にある: {$file}({$path})");
        }

        $relative = substr($file, strlen($prefix));
        $matches = [];

        foreach ($suitePaths as $suite => $directories) {
            $length = self::matchedLength($relative, $directories);

            if ($length >= 0) {
                $matches[$suite] = $length;
            }
        }

        if ($matches === []) {
            throw new UnexpectedValueException("junit の testcase の file がどの suite のディレクトリにも属さない: {$file}({$path})");
        }

        $longest = max($matches);
        $suites = array_keys(array_filter($matches, fn (int $length): bool => $length === $longest));

        if (count($suites) > 1) {
            throw new UnexpectedValueException('suite の走査先が重なる: '.implode('・', $suites)." が同じ長さのディレクトリで {$file} に一致する({$path})");
        }

        return (string) $suites[0];
    }

    /**
     * $relative が $directories のどれかの下にあるとき、一致したディレクトリのうち最長の文字数。無ければ -1。
     *
     * @param  list<string>  $directories
     */
    private static function matchedLength(string $relative, array $directories): int
    {
        $longest = -1;

        foreach ($directories as $directory) {
            $directory = trim($directory, '/');

            if ($directory !== '' && str_starts_with($relative, $directory.'/')) {
                $longest = max($longest, strlen($directory));
            }
        }

        return $longest;
    }

    private static function hasChild(DOMElement $element, string $name): bool
    {
        return $element->getElementsByTagName($name)->length > 0;
    }
}
