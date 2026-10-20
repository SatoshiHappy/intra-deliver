<?php

namespace Tests\Unit\Queries\Workflow;

use App\Queries\Workflow\ApplicationBaseDates;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 申請基準日の読み方と「今日の前後 1 年」の 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.1 の (1)・(2))。読み方は業務処理の 422 と申請の画面の初期値が、
 * 範囲は業務処理の 422 と min/max が同じ口を使う(区切り 4-1 のレビュー #10。画面の初期値は範囲で捨てない = 通しの回の T04・T06)。
 */
final class ApplicationBaseDatesTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function inputs(): array
    {
        return [
            '暦の日付' => ['2026-10-13', '2026-10-13'],
            '前後の空白' => [' 2026-10-13 ', '2026-10-13'],
            '閏日' => ['2028-02-29', '2028-02-29'],
            '暦に無い日' => ['2026-02-30', null],
            '閏年でない 2 月 29 日' => ['2027-02-29', null],
            '形が違う' => ['2026/10/13', null],
            '空' => ['', null],
            '空白だけ' => ['  ', null],
            '文字列でない' => [20261013, null],
            '無い' => [null, null],
        ];
    }

    #[DataProvider('inputs')]
    public function test_parses_only_a_calendar_date_of_yyyy_mm_dd(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, ApplicationBaseDates::parse($input)?->format(ApplicationBaseDates::FORMAT));
    }

    /**
     * 範囲は今日の前後 1 年で両端を含む。今日が 2 月 29 日なら 1 年前・1 年後は 2 月 28 日。
     */
    public function test_the_range_is_a_year_around_today_including_both_ends(): void
    {
        $today = CarbonImmutable::parse('2028-02-29');

        $this->assertSame(['2027-02-28', '2029-02-28'], [ApplicationBaseDates::earliest($today)->format('Y-m-d'), ApplicationBaseDates::latest($today)->format('Y-m-d')]);
        $this->assertSame(
            [false, true, true, true, false],
            array_map(fn (string $date) => ApplicationBaseDates::isWithinYear(CarbonImmutable::parse($date), $today), ['2027-02-27', '2027-02-28', '2028-02-29', '2029-02-28', '2029-03-01']),
        );
    }
}
