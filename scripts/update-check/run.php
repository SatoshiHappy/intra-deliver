<?php

/*
 * 更新チェックの実行本体(docs の 設計/更新チェック.md)。.github/workflows/update-check.yml の調べるジョブと、ローカルの実行から呼ぶ。
 *
 * 依存と固定したイメージの更新を調べ(#1〜#8)、結果の Markdown を最後にまとめて書く(Job Summary 用の全文 summary.md と、コメント用 comment.md)。
 * 外部コマンド(composer・npm・docker)は配列で渡して呼び、シェルを介さない。vendor を使わない(runner では composer install をしない)。
 * 見つかったもの(要対応・更新あり)と確認できなかった項目では失敗にしない。報告が役目で、失敗の判定は書くジョブがコメントを書いた後で行う。
 *
 * 使い方: php scripts/update-check/run.php [--out=<結果を書くディレクトリ。既定 build/update-check>] [--cleanup]
 *   --cleanup = 作った一時のイメージと Trivy のキャッシュを消す(ローカルの実行用)。
 * 実行の URL は環境変数 GITHUB_SERVER_URL・GITHUB_REPOSITORY・GITHUB_RUN_ID から作る(無ければ「ローカル」)。
 */

use UpdateCheck\ImageScanner;
use UpdateCheck\ProcessCommandRunner;
use UpdateCheck\ResultFiles;
use UpdateCheck\RunOptions;
use UpdateCheck\StreamHttpClient;
use UpdateCheck\TimeBudget;
use UpdateCheck\UpdateChecker;

require __DIR__.'/autoload.php';

$root = dirname(__DIR__, 2);

try {
    $options = RunOptions::parse(array_slice($argv, 1), $root.'/build/update-check');
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL.RunOptions::USAGE.PHP_EOL);
    exit(2);
}

$server = (string) getenv('GITHUB_SERVER_URL');
$repository = (string) getenv('GITHUB_REPOSITORY');
$runId = (string) getenv('GITHUB_RUN_ID');
$runUrl = $server !== '' && $repository !== '' && $runId !== '' ? "{$server}/{$repository}/actions/runs/{$runId}" : '';

// スクリプト全体の時間の予算(25 分。ジョブの 30 分から結果を書く分を残す)。各コマンド・HTTP の上限は予算の残りを超えない
$budget = TimeBudget::starting();
$commands = new ProcessCommandRunner($budget);
$report = (new UpdateChecker($commands, new StreamHttpClient($budget), $budget, $root, $runUrl, new DateTimeImmutable('now', new DateTimeZone('UTC'))))->check();

// 結果は最後にまとめて書く(異常終了した回と書き切れなかった回は結果のファイルが無く、書くジョブがそれを失敗として扱う)。summary.md を先に置く(comment.md があれば summary.md も書けている)
try {
    ResultFiles::write($options->out, ['summary.md' => $report->full(), 'comment.md' => $report->comment()]);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

echo $report->headline().PHP_EOL;

if ($options->cleanup) {
    foreach ((new ImageScanner($commands, $root, $budget, static fn (): string => ''))->cleanup() as $line) {
        fwrite(STDERR, $line.PHP_EOL);
    }
}
