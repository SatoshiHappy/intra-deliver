<?php

namespace Tests\Architecture\Engine;

use Illuminate\Process\Factory;
use UnexpectedValueException;

/**
 * 外部コマンドを実行する(汎用)。検査ツールを fixture に対してサブプロセスで走らせるのに使う。
 */
final class Process
{
    /**
     * コマンドを作業ディレクトリ $cwd で実行し、終了を待って結果を返す。シェルは介さない。
     * 時間切れは例外(Illuminate\Process\Exceptions\ProcessTimedOutException)のまま呼び元へ返す。
     *
     * @param  list<string>  $command  実行ファイルと引数
     * @param  array<string, string>  $environment  追加・上書きする環境変数(親プロセスの環境は引き継ぐ)
     *
     * @throws UnexpectedValueException 終了コードが得られないとき
     */
    public static function run(array $command, string $cwd, array $environment = [], int $timeoutSeconds = 300): ProcessResult
    {
        $result = (new Factory)
            ->newPendingProcess()
            ->path($cwd)
            ->env($environment)
            ->timeout($timeoutSeconds)
            ->run($command);

        $exitCode = $result->exitCode();

        if ($exitCode === null) {
            throw new UnexpectedValueException('終了コードが得られない: '.implode(' ', $command));
        }

        return new ProcessResult($command, $exitCode, $result->output(), $result->errorOutput());
    }
}
