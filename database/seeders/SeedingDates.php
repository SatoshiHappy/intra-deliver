<?php

namespace Database\Seeders;

use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * 初期データの日付を流す日からの相対にする 1 か所(docs の 設計/ワークフロー/案件の運用/初期データ.md 1.1)。
 * 固定の日付は日がたつと前後関係(入社予定・版の期間・申請基準日の範囲)が崩れるので、seeder の定数は基準の日の値で持ち、ここを通す。
 */
final class SeedingDates
{
    /** 基準の日(seeder の定数の日付を書いた時の日) */
    public const BASE = '2026-10-15';

    /** 日付の形(設定の値・業務データの日付の項目) */
    private const FORMAT = 'Y-m-d';

    /**
     * 流す日を 1 回だけ決めて設定に置く(流している間に日付が変わっても、どの日付も同じ流す日から決めるため)。DatabaseSeeder が最初に呼ぶ。
     *
     * @throws InvalidArgumentException 設定の値が YYYY-MM-DD の暦の日付でないとき
     */
    public static function fixSeedingDay(): void
    {
        config(['seeding.date' => self::seedingDay()->format(self::FORMAT)]);
    }

    /**
     * 流す日(Asia/Tokyo の 0 時。操作の基準日と、流す日に置く申請基準日)。
     *
     * @throws InvalidArgumentException 設定の値が YYYY-MM-DD の暦の日付でないとき
     */
    public static function seedingDay(): CarbonImmutable
    {
        $configured = config('seeding.date');

        if ($configured === null) {
            return CarbonImmutable::today(AccessContext::TIMEZONE);
        }

        if (! is_string($configured) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $configured, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException(sprintf(
                '初期データの流す日(設定 seeding.date = 環境変数 SEED_DATE)は YYYY-MM-DD の暦の日付で書く(今の値 = %s)',
                var_export($configured, true),
            ));
        }

        return CarbonImmutable::parse($configured, AccessContext::TIMEZONE);
    }

    /**
     * 基準の日に書いた日付(YYYY-MM-DD)を、流す日からの相対の日付にする(null はそのまま)。
     *
     * @return ($date is null ? null : string)
     */
    public static function day(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return CarbonImmutable::parse($date, 'UTC')->addDays(self::offsetDays())->format(self::FORMAT);
    }

    /**
     * {@see self::day()} の日付を Carbon にする(期間・版の開始日に渡す)。
     */
    public static function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(self::day($date));
    }

    /**
     * 基準の日に書いた日時(ISO 8601。時差つき)を、時刻と時差を保って同じ日数だけずらす(null はそのまま)。
     *
     * @return ($dateTime is null ? null : string)
     */
    public static function dateTime(?string $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        return CarbonImmutable::parse($dateTime)->addDays(self::offsetDays())->format(DATE_ATOM);
    }

    /**
     * 基準の日から流す日までの日数(流す日が前なら負)。
     */
    private static function offsetDays(): int
    {
        $seedingDay = CarbonImmutable::parse(self::seedingDay()->format(self::FORMAT), 'UTC');

        return (int) CarbonImmutable::parse(self::BASE, 'UTC')->diffInDays($seedingDay);
    }
}
