<?php

namespace App\Actions\Ai\Core;

use DateTimeImmutable;

/**
 * 時計と待ちの口(受け手。docs の 設計/AI/会話の中核の口と受け手.md 13.3)。送り直しの待ちは、途中の文を書く間隔ごとに「待つ → 確かめ」を
 * 繰り返す。テストで待たずに進める。
 */
interface CoreClock
{
    /** 今の時刻(今日の日は東京の日で数える) */
    public function now(): DateTimeImmutable;

    /** 待つ(ミリ秒) */
    public function wait(int $milliseconds): void;
}
