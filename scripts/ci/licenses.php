<?php

/*
 * D 依存のライセンスの検査(ADR 0009: AGPL / GPL のコードは入れない)の実行本体。scripts/ci/deps から呼ぶ。
 *
 * 許可するライセンスと台帳のキーは Policy(tests/Architecture/Policy/Licenses.php)、判定本体は Engine(tests/Architecture/Engine の
 * LicenseAudit・LicenseInventory・SpdxExpression)に置き、ここは入力(composer と npm の本番の依存)を集めて判定を呼び、結果を出すだけ。
 * 入力 = `composer licenses --format=json --no-dev`、`npm ls --all --json --long --omit=dev`(各項目の path の package.json の license 欄を読む)、
 * package-lock.json の packages(npm ls が依存の辺を持たない extraneous の項目の本番・開発を決める)。
 * 期限の日付は Asia/Tokyo の暦日で判定する(実行環境のタイムゾーンに依らない)。
 * 例外 = 台帳⑧(config/guardrails_allow.php の exclusions の #7)の package の行。期限つきで、台帳にあって実体に無ければ失敗。
 *
 * 出力 = 失敗は 1 件 1 行(NG  種類 名前 版 [ライセンス]: 理由)、成功は件数だけ。失敗があれば 1 で終わる。
 */

use Tests\Architecture\Engine\ExclusionLedger;
use Tests\Architecture\Engine\JsonCommand;
use Tests\Architecture\Engine\LicenseAudit;
use Tests\Architecture\Engine\LicenseInventory;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Policy\Licenses;

$appRoot = dirname(__DIR__, 2);

require $appRoot.'/vendor/autoload.php';

try {
    $allow = require $appRoot.'/config/guardrails_allow.php';
    $exceptions = ExclusionLedger::fromArray(is_array($allow) ? ($allow['exclusions'] ?? null) : null, Licenses::LEDGER_CHECK)->packageExpirations(Licenses::LEDGER_CHECK);

    // npm ls は依存の不整合(missing など)で終了コード 1 + error.code = ELSPROBLEMS を返しつつ木を出す。JSON が読めて、終了コードが 0 か ELSPROBLEMS なら監査に進む(木の形は LicenseInventory::npm が検査する)
    // (missing の項目は LicenseInventory が失敗として記録する。それ以外の不整合は npm ls の問題として表示するだけで、整合は同じ段の npm ci --dry-run が見る)
    $npmResult = Process::run(['npm', 'ls', '--all', '--json', '--long', '--omit=dev'], $appRoot);
    $npmOutput = json_decode($npmResult->stdout, true);
    $npmProblems = is_array($npmOutput) && is_array($npmOutput['error'] ?? null) && ($npmOutput['error']['code'] ?? null) === 'ELSPROBLEMS';

    if (! is_array($npmOutput) || ($npmResult->exitCode !== 0 && ! $npmProblems)) {
        throw new UnexpectedValueException("npm ls の出力を使えない\n".$npmResult->describe());
    }

    foreach (is_array($npmOutput['problems'] ?? null) ? $npmOutput['problems'] : [] as $problem) {
        echo 'INFO  npm ls の問題: '.(is_string($problem) ? $problem : json_encode($problem)).PHP_EOL;
    }

    $composerOutput = JsonCommand::run(['composer', 'licenses', '--format=json', '--no-dev', '--no-interaction'], $appRoot);

    if (! is_array($composerOutput)) {
        throw new UnexpectedValueException('composer の出力の最上位が表でない');
    }

    $composer = LicenseInventory::composer($composerOutput);
    $lock = Values::jsonFile($appRoot.'/package-lock.json');
    $npm = LicenseInventory::npm($npmOutput, Values::table($lock['packages'] ?? null, 'package-lock.json の packages'));
} catch (Throwable $exception) {
    echo 'NG  ライセンスの入力を集められない('.$exception::class.')'.PHP_EOL;
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

$failures = (new LicenseAudit(Licenses::ALLOWED, $exceptions, new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'))))->failures([...$composer, ...$npm]);

foreach ($failures as $failure) {
    echo 'NG  '.$failure.PHP_EOL;
}

if ($failures === []) {
    echo 'OK  ライセンス — composer '.count($composer).' 件・npm '.count($npm).' 件'.PHP_EOL;
}

exit($failures === [] ? 0 : 1);
