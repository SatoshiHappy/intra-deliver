<?php

/*
 * T の後に、時間の長いテストのクラスを上から 10 出す(引数 = junit のパス)。止まった回の原因のクラスを特定するため。
 * 数え方と出力の形は docs の 設計/コード構成/検査/一括検査の段.md 6.5 の並列(T)。読めない junit・欠けた testcase は理由を出して 1 で終わる。
 */

use Tests\Architecture\Engine\XmlFile;

$appRoot = dirname(__DIR__, 2);

require $appRoot.'/vendor/autoload.php';

$limit = 10;
$arguments = $_SERVER['argv'] ?? [];

if (! is_array($arguments) || count($arguments) !== 2 || ! is_string($arguments[1])) {
    fwrite(STDERR, "使い方: php scripts/ci/slow-tests.php <junit のパス>\n");
    exit(2);
}

/** @var array<string, array{seconds: float, tests: int}> $classes */
$classes = [];
$total = 0.0;

try {
    $nodes = XmlFile::xpath($arguments[1])->query('//testcase');

    if ($nodes === false) {
        throw new UnexpectedValueException("junit の testcase を読めない: {$arguments[1]}");
    }

    foreach ($nodes as $node) {
        if (! $node instanceof DOMElement) {
            continue;
        }

        $class = $node->getAttribute('class');

        if ($class === '') {
            throw new UnexpectedValueException("class の無い testcase がある: {$node->getAttribute('name')}");
        }

        // time の無い・数でない testcase を 0 秒に数えると、欠けを「遅くない」と読ませる
        if (! $node->hasAttribute('time') || ! is_numeric($node->getAttribute('time'))) {
            $name = $node->getAttribute('name');

            throw new UnexpectedValueException("time が無い、または数でない testcase がある: {$class}::{$name}");
        }

        $seconds = (float) $node->getAttribute('time');
        $classes[$class] ??= ['seconds' => 0.0, 'tests' => 0];
        $classes[$class]['seconds'] += $seconds;
        $classes[$class]['tests']++;
        $total += $seconds;
    }

    if ($classes === []) {
        throw new UnexpectedValueException("testcase が 0 件の junit: {$arguments[1]}");
    }
} catch (InvalidArgumentException|UnexpectedValueException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

// 長い順。同じ時間はクラスの名前の順(出力を揺らさない)
uksort($classes, fn (string $a, string $b): int => [$classes[$b]['seconds'], $a] <=> [$classes[$a]['seconds'], $b]);

echo "==> 時間の長いテストのクラス(上位 {$limit}。testcase の時間のクラスごとの和)\n";
printf("testcase の時間の総和 %.1f 秒(%d 件)\n", $total, array_sum(array_column($classes, 'tests')));

foreach (array_slice($classes, 0, $limit, true) as $class => $run) {
    printf("%9.1f 秒 %5d 件  %s\n", $run['seconds'], $run['tests'], $class);
}
