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
     * 既定の上限 1200 秒は、止まったまま戻らないツールを止めるためのもの。ディスクが遅い機械では、ファイルが OS の控えに
     * 無い作業木の最初の 1 回がディスクの読みで長くなる(fixture の phpstan が、ほかの検査の無い時に 581 秒。2026-10-21)。
     *
     * @param  list<string>  $command  実行ファイルと引数
     * @param  array<string, string>  $environment  追加・上書きする環境変数(親プロセスの環境は引き継ぐ)
     *
     * @throws UnexpectedValueException 終了コードが得られないとき
     */
    public static function run(array $command, string $cwd, array $environment = [], int $timeoutSeconds = 1200): ProcessResult
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
