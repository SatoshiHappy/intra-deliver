<?php

namespace App\Actions\Ai\Core;

use LogicException;

/**
 * 確かめの口に渡す物(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「確かめの口」。字数を数える範囲も同じ項)。送る前の位置だけが量を持つ。
 */
final readonly class CheckRequest
{
    private function __construct(
        public CheckPoint $point,
        public SendKind $kind,
        public ?int $chars = null,
        public ?int $outputLimit = null,
        public ?int $sendLimit = null,
    ) {}

    /**
     * 送る前の位置。
     *
     * @param  int  $chars  送る文の全体の字数(トークンでなく文字数)
     * @param  int  $outputLimit  その送信の出力の上限
     * @param  int  $sendLimit  その送信の送る量の上限(種別ごとの値 = 13.3 の確かめの口)
     */
    public static function beforeSend(SendKind $kind, int $chars, int $outputLimit, int $sendLimit): self
    {
        return new self(CheckPoint::BeforeSend, $kind, $chars, $outputLimit, $sendLimit);
    }

    /**
     * 送る前のほかの位置($kind = その時の送信の種別。道具の位置では、その呼び出しを返した送信の種別)。
     *
     * @throws LogicException 送る前の位置を渡したときと、位置が受けない送信の種別のとき(作りの誤り = {@see CheckPoint::receives()})
     */
    public static function at(CheckPoint $point, SendKind $kind): self
    {
        if ($point === CheckPoint::BeforeSend) {
            throw new LogicException('送る前の位置は beforeSend で作る(量を渡す)');
        }

        if (! $point->receives($kind)) {
            throw new LogicException("確かめの位置 {$point->value} で送信の種別 {$kind->value} の確かめは起きない");
        }

        return new self($point, $kind);
    }
}
