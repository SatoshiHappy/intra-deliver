<?php

namespace App\Actions\Ai\Api;

use LogicException;
use RuntimeException;
use Throwable;

/**
 * 種類の完了の受け手が予期する失敗(出力の形・長さが合わない、文字起こしの区間が空など)に投げる例外。実行は失敗(completion)になり、
 * 理由のコードがそのまま {@see FailedRun::$reason} に渡る(Ai はコードを解釈しない。コードの一覧は受け手のモジュールが持つ)。
 * レコードの 403・404・422 は入れ物の例外の型のまま投げる(Ai が型で理由を決める = docs の 設計/AI/データと失敗と検査.md 7 節)。
 */
final class RunCompletionFailed extends RuntimeException
{
    private const REASON_PATTERN = '/^[a-z_]{1,32}$/D';

    /**
     * @param  string  $reason  理由のコード(英小文字と _、32 字まで。受け手が決めるコードは {@see FailedRun} と {@see RunRequestRejected} の定数と重ねない)
     * @param  string  $message  例外の文(実行の記録の例外の文に残る。画面には出さない)
     *
     * @throws LogicException 理由のコードの形が違うとき(作りの誤り)
     */
    public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        if (preg_match(self::REASON_PATTERN, $reason) !== 1) {
            throw new LogicException("AI の完了の失敗の理由のコード {$reason} は英小文字と _ の 32 字までにする");
        }

        parent::__construct($message, 0, $previous);
    }
}
