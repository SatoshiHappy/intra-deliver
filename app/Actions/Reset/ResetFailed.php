<?php

namespace App\Actions\Reset;

use RuntimeException;
use Throwable;

/**
 * 記録の行を書いた後の手順 1〜7 の失敗(docs の 設計/公開の配備/毎日の初期化.md 4 節)。
 * コマンドが出力するこの回の結果と、元の例外(previous)を持つ。
 */
class ResetFailed extends RuntimeException
{
    public function __construct(public readonly ResetReport $report, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
