<?php

namespace App\Actions\Ai\ToolCalls;

use Closure;
use RuntimeException;
use Throwable;

/**
 * 道具の呼び出しの中の予期しない例外(包みの中の例外と道具の例外。docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4)。
 * 元の例外を前の例外に持ち、包みが報告して道具の名前だけの決まった失敗の文にする(例外の文は結果に入れない)。見つからない・権限が無いなど、
 * 既定では報告しない例外も、ここでは予期しない例外なので、この型で報告する。
 */
final class ToolCallException extends RuntimeException
{
    /** 包みの中の例外(3〜5 の中の予期しない例外) */
    public const WRAPPER = '包みの中の例外';

    /** 道具の例外(6 の道具の実行と結果の写しの中の例外) */
    public const TOOL = '道具の例外';

    /**
     * $work を呼び、どの例外もこの型にして投げ直す。
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws self $work が投げたとき
     */
    public static function during(ModelToolCall $call, string $kind, Closure $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $exception) {
            throw new self("道具 {$call->name} の呼び出し {$call->id} の{$kind}", 0, $exception);
        }
    }
}
