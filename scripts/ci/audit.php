<?php

/*
 * D 依存の監査(composer audit・npm audit)の実行本体。scripts/ci/deps から呼ぶ。
 * 見つかったもの(advisory・放棄されたパッケージ)は門で止めず、注意として出す(外した理由と代わりの気づき方 = docs の
 * 設計/コード構成/検査.md 6 節)。監査が走らない・出力を読めないときは失敗にする(結果が無いのを 0 件に見せない)。
 * 読み方と行の列は更新チェックの #3(AuditCheck)と同じ。
 */

use UpdateCheck\AuditCheck;
use UpdateCheck\ProcessCommandRunner;
use UpdateCheck\TimeBudget;

$root = dirname(__DIR__, 2);

require $root.'/scripts/update-check/autoload.php';

// WARN の行の記録(毎回書き直す)。scripts/ci/all が D を走らせた回に数えて、結果の一覧に出す
$record = $root.'/build/dependency-audit.txt';

// 外から取った文字列が workflow command に読まれないようにする(GitHub Actions は行頭の :: と、行のどこにある ##[ も読む)
$oneLine = static fn (string $text): string => strtr($text, ["\r" => ' ', "\n" => ' ', '##[' => '## [']);

$section = (new AuditCheck(new ProcessCommandRunner(TimeBudget::starting()), $root))->run();

foreach ($section->unconfirmed as $unconfirmed) {
    echo $oneLine("NG  {$unconfirmed->subject}: {$unconfirmed->reason}").PHP_EOL;
}

$warnings = array_map(fn ($row): string => $oneLine('WARN  '.implode(' | ', $row->cells)), $section->rows);

foreach ($warnings as $warning) {
    echo $warning.PHP_EOL;
}

if ($warnings !== []) {
    echo 'INFO  詳しく(advisory の ID・URL)は composer audit --locked と npm audit で見る'.PHP_EOL;
}

// 注意の表示 = GitHub Actions の workflow command(warning)。実行の画面に出る
if ($warnings !== [] && getenv('GITHUB_ACTIONS') === 'true') {
    $count = count($warnings);
    echo "::warning title=依存の監査::WARN の行 {$count} 件(門では止めない。ログの WARN の行と docs の 設計/コード構成/検査.md 6 節)".PHP_EOL;
}

$lines = implode('', array_map(fn (string $warning): string => $warning.PHP_EOL, $warnings));
$written = (is_dir(dirname($record)) || mkdir(dirname($record), 0777, true))
    && file_put_contents($record, $lines) !== false;

if (! $written) {
    echo "NG  WARN の行の記録を書けない: {$record}".PHP_EOL;
    exit(1);
}

if ($section->unconfirmed === [] && $warnings === []) {
    echo 'OK  依存の監査 — composer・npm とも 0 件'.PHP_EOL;
}

exit($section->unconfirmed === [] ? 0 : 1);
