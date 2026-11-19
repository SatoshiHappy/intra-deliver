<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\CoreChecker;
use App\Actions\Ai\Core\SendKind;
use LogicException;

/**
 * 確かめの口を呼び、位置に合わない返り値を作りの誤りとして投げる(合うかの正本 = {@see CheckPoint::accepts()}。
 * docs の 設計/AI/会話の中核.md 13.5 の「起きない」の止める制約)。位置が受けない種別では呼ばずに続ける(同じ 13.5 の表)。
 */
final class SubstituteChecks
{
    /**
     * @throws LogicException 位置に合わない返り値のとき
     */
    public static function ask(CoreChecker $checker, CheckRequest $request): CheckReply
    {
        $reply = $checker->check($request);

        if (! $request->point->accepts($reply, $request->kind)) {
            throw new LogicException("確かめの口が {$request->point->value} の位置に合わない返り値を返した");
        }

        return $reply;
    }

    public static function at(CoreChecker $checker, CheckPoint $point, SendKind $kind): CheckReply
    {
        return $point->receives($kind) ? self::ask($checker, CheckRequest::at($point, $kind)) : CheckReply::proceed();
    }
}
