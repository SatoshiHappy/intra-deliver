<?php

namespace App\Enums\Reset;

/**
 * 初期化の動かし方(docs の 設計/公開の配備/毎日の初期化.md 2 節の「始めるか」の表。表 reset_runs の trigger)。
 */
enum ResetTrigger: string
{
    /** スケジューラが毎分の確かめで動かした */
    case Schedule = 'schedule';

    /** 運営者がコマンドで動かした(6 節) */
    case Manual = 'manual';
}
