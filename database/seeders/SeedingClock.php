<?php

namespace Database\Seeders;

use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 見本の操作の時計(docs の 設計/ワークフロー/案件の運用/初期データ.md 1.2)。操作の前にアプリの時計を基準の日に書いた日時から決めた時刻に合わせ、
 * 全部の見本の後に合わせる前の時計へ戻す。手段は Carbon の試験の時刻(Carbon と CarbonImmutable が共有し、now()・Date::now()・Eloquent の
 * created_at が読む。Carbon は本番の依存なので --no-dev の初期データでも使える)。
 */
final class SeedingClock
{
    /**
     * 見本を入れる処理を呼び、終わった後に時計を呼ぶ前の状態へ戻す(例外のときも戻す)。
     * 流す日は時計を動かす前に固め、戻さない(動かした後の今日を流す日と読まず、後の処理も同じ流す日を使うため)。
     */
    public static function restoring(callable $seed): void
    {
        SeedingDates::fixSeedingDay();
        $previous = CarbonImmutable::getTestNow();

        try {
            $seed();
        } finally {
            CarbonImmutable::setTestNow($previous);
        }
    }

    /**
     * 時計を合わせ、その日(Asia/Tokyo の 0 時 = 操作の判定の入力の基準日)を返す。
     *
     * @param  string  $at  基準の日に書いた Asia/Tokyo の日時(Y-m-d H:i。流す日からの相対にする = {@see SeedingDates::dateTime()})
     */
    public static function set(string $at): CarbonImmutable
    {
        $written = CarbonImmutable::parse($at, AccessContext::TIMEZONE)->format(DATE_ATOM);
        $moment = CarbonImmutable::parse(SeedingDates::dateTime($written))->setTimezone(AccessContext::TIMEZONE);
        CarbonImmutable::setTestNow($moment);

        return $moment->startOfDay();
    }
}
