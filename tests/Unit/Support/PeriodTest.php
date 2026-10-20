<?php

namespace Tests\Unit\Support;

use App\Support\Period;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 日付の半開区間(docs の 設計/共通マスタ/共通の規則.md 5.1: 開始日を含み終了日を含まない、終了日 null は上限なし、長さ 0 は作れない)と、
 * 業務処理の規則が使う和・包含。
 */
final class PeriodTest extends TestCase
{
    public function test_rejects_an_end_that_is_not_after_the_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->period('2024-04-01', '2024-04-01');
    }

    public function test_includes_the_start_date_but_not_the_end_date_and_ignores_the_time(): void
    {
        $period = $this->period('2024-04-01', '2025-04-01');

        $this->assertSame([false, true, true, false], array_map(
            fn (string $date) => $period->includes(CarbonImmutable::parse($date)),
            ['2024-03-31 23:59:59', '2024-04-01', '2025-03-31 23:59:59', '2025-04-01'],
        ));
        $this->assertTrue($this->period('2024-04-01')->includes(CarbonImmutable::parse('9999-12-31')));
    }

    public function test_contains_only_periods_inside_it(): void
    {
        $period = $this->period('2024-04-01', '2025-04-01');

        $this->assertTrue($period->contains($this->period('2024-04-01', '2025-04-01')));
        $this->assertFalse($period->contains($this->period('2024-03-31', '2025-04-01')));
        $this->assertFalse($period->contains($this->period('2024-04-01', '2025-04-02')));
        $this->assertFalse($period->contains($this->period('2024-04-01')));
        $this->assertTrue($this->period('2024-04-01')->contains($this->period('2030-01-01')));
    }

    public function test_the_union_joins_overlapping_and_adjacent_periods_but_keeps_gaps(): void
    {
        $union = Period::union([
            $this->period('2025-01-01'),
            $this->period('2020-01-01', '2021-01-01'),
            $this->period('2021-01-01', '2022-01-01'),
            $this->period('2021-06-01', '2021-07-01'),
            $this->period('2023-01-01', '2024-01-01'),
        ]);

        $this->assertSame([['2020-01-01', '2022-01-01'], ['2023-01-01', '2024-01-01'], ['2025-01-01', null]], array_map(
            fn (Period $period) => [$period->start->toDateString(), $period->end?->toDateString()],
            $union,
        ));
    }

    public function test_covers_needs_one_joined_span_over_the_whole_period(): void
    {
        $periods = [$this->period('2020-01-01', '2024-06-01'), $this->period('2024-06-01', '2025-01-01'), $this->period('2025-02-01')];

        $this->assertTrue(Period::covers($periods, $this->period('2024-04-01', '2025-01-01')));
        $this->assertFalse(Period::covers($periods, $this->period('2024-04-01', '2025-01-02')));
        $this->assertTrue(Period::covers($periods, $this->period('2025-02-01')));
        $this->assertFalse(Period::covers($periods, $this->period('2019-12-31', '2020-02-01')));
        $this->assertFalse(Period::covers([], $this->period('2020-01-01', '2020-01-02')));
    }

    public function test_the_span_including_a_day_is_the_joined_period_around_it(): void
    {
        $periods = [$this->period('2020-01-01', '2024-06-01'), $this->period('2024-06-01', '2025-01-01')];

        $this->assertSame('2025-01-01', Period::spanIncluding($periods, CarbonImmutable::parse('2020-01-01'))?->end?->toDateString());
        $this->assertNull(Period::spanIncluding($periods, CarbonImmutable::parse('2025-01-01')));
    }

    private function period(string $start, ?string $end = null): Period
    {
        return new Period(CarbonImmutable::parse($start), $end === null ? null : CarbonImmutable::parse($end));
    }
}
