<?php

namespace UpdateCheck;

use Throwable;

/**
 * 例外を「確認できなかった」の 1 件にする(項目の実行・前処理で共通)。どの例外でも、1 つの失敗が残りを止めない(設計/更新チェック.md 5 節)。
 * 想定した失敗(UnconfirmedException)は文だけ。想定外の例外は種類・文・場所を理由にし、スタックトレースを error_log() で残す(CLI では既定で標準エラー = ジョブのログ)。
 */
final class Unconfirmeds
{
    public static function from(string $subject, Throwable $exception): Unconfirmed
    {
        if ($exception instanceof UnconfirmedException) {
            return new Unconfirmed($subject, $exception->getMessage());
        }

        error_log("想定外の失敗({$subject}): ".$exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString());

        return new Unconfirmed($subject, sprintf(
            '想定外の失敗 %s: %s(%s:%d)',
            $exception::class,
            $exception->getMessage(),
            basename($exception->getFile()),
            $exception->getLine(),
        ));
    }
}
