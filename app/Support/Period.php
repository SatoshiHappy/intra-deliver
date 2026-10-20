<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * 日付の半開区間 [start, end)(docs の 設計/共通マスタ/共通の規則.md 5.1)。end が null なら上限なし。
 * 日付だけを持つ(渡された日時の暦日を使い、時刻を捨てる)。
 */
final readonly class Period
{
    public CarbonImmutable $start;

    public ?CarbonImmutable $end;

    /**
     * @throws InvalidArgumentException 終了日が開始日より後でないとき(長さ 0 の期間は作れない = 5.1)
     */
    public function __construct(DateTimeInterface $start, ?DateTimeInterface $end = null)
    {
        $this->start = self::day($start);
        $this->end = $end === null ? null : self::day($end);

        if ($this->end !== null && $this->end->lessThanOrEqualTo($this->start)) {
            throw new InvalidArgumentException("期間の終了日({$this->end->toDateString()})は開始日({$this->start->toDateString()})より後にする");
        }
    }

    /**
     * 日が期間に入るか(開始日を含み、終了日を含まない)。
     */
    public function includes(DateTimeInterface $date): bool
    {
        $day = self::day($date);

        return $day->greaterThanOrEqualTo($this->start) && ($this->end === null || $day->lessThan($this->end));
    }

    /**
     * ほかの期間がこの期間の中に収まるか。
     */
    public function contains(self $other): bool
    {
        return $other->start->greaterThanOrEqualTo($this->start) && ($this->end === null || ($other->end !== null && $other->end->lessThanOrEqualTo($this->end)));
    }

    /**
     * 期間の和(重なる・接する期間をつないだもの。開始日の順)。
     *
     * @param  array<int, self>  $periods
     * @return list<self>
     */
    public static function union(array $periods): array
    {
        usort($periods, fn (self $a, self $b) => $a->start <=> $b->start);
        $merged = [];

        foreach ($periods as $period) {
            $last = array_pop($merged);

            if ($last === null) {
                $merged[] = $period;
            } elseif ($last->end === null || $period->start->lessThanOrEqualTo($last->end)) {
                $merged[] = new self($last->start, self::later($last->end, $period->end));
            } else {
                $merged[] = $last;
                $merged[] = $period;
            }
        }

        return $merged;
    }

    /**
     * 期間の和のうち、日を含む期間(無ければ null)。
     *
     * @param  array<int, self>  $periods
     */
    public static function spanIncluding(array $periods, DateTimeInterface $date): ?self
    {
        foreach (self::union($periods) as $span) {
            if ($span->includes($date)) {
                return $span;
            }
        }

        return null;
    }

    /**
     * 期間の和が、期間の全日を覆うか。
     *
     * @param  array<int, self>  $periods
     */
    public static function covers(array $periods, self $period): bool
    {
        return self::spanIncluding($periods, $period->start)?->contains($period) ?? false;
    }

    private static function day(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d')) ?: throw new InvalidArgumentException('日付として読めない');
    }

    private static function later(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return $a === null || $b === null ? null : $a->max($b);
    }
}
