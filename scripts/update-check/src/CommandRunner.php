<?php

namespace UpdateCheck;

/**
 * 外部コマンドを実行する。引数は配列で渡し、シェルは介さない(外の値を文字列に混ぜない)。
 */
interface CommandRunner
{
    /**
     * 上限は $timeoutSeconds と時間の予算の残りの小さいほう。$cleanup のコマンド(時間切れの後の後始末)は予算の残りに依らず $timeoutSeconds で流す。
     *
     * @param  list<string>  $command  実行ファイルと引数
     *
     * @throws UnconfirmedException 起動できない・時間切れ・予算切れ
     */
    public function run(array $command, string $cwd, int $timeoutSeconds = 300, bool $cleanup = false): CommandResult;
}
