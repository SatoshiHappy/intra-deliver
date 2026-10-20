<?php

/*
 * M 台帳・網羅・実行結果の照合の実行本体。scripts/ci/verify から呼ぶ。
 *
 * 照合の一覧と固有の値は Policy(tests/Architecture/Policy/Verification.php)、判定本体は Engine(tests/Architecture/Engine)に置き、
 * ここは Laravel を起動して台帳(config/guardrails_allow.php・config/guardrails_ledger.php)を読み、照合を順に実行して結果を出すだけ
 * (app/ に検査コードを置かない)。
 *
 * 出力 = 照合ごとに 1 行(OK / NG・照合の名前・要約か差分)。照合が例外で止まったものも NG とし、理由は標準エラーに出す。
 * 1 つでも NG なら 1 で終わる。
 */

use Illuminate\Contracts\Console\Kernel;
use Tests\Architecture\Policy\Verification;

$appRoot = dirname(__DIR__, 2);

require $appRoot.'/vendor/autoload.php';

$app = require $appRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$exitCode = 0;
$checks = Verification::checks($appRoot, config('guardrails_allow'), config('guardrails_ledger'));

foreach ($checks as $name => $check) {
    try {
        $result = $check();
    } catch (Throwable $exception) {
        echo "NG  {$name}: 照合できない(".$exception::class.')'.PHP_EOL;
        fwrite(STDERR, "{$name}: {$exception->getMessage()}".PHP_EOL);
        $exitCode = 1;

        continue;
    }

    echo $result->line($name).PHP_EOL;
    $exitCode = max($exitCode, $result->exitCode());
}

exit($exitCode);
