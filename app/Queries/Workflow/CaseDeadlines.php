<?php

namespace App\Queries\Workflow;

use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;

/**
 * 到達日時・期限日・経過日数の計算の 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.4 節)。日付は Asia/Tokyo の暦日
 * (Y-m-d の文字列)。今日は呼び手が渡す基準日(日付だけを使う)。問い合わせをしない。
 */
class CaseDeadlines
{
    private const DATE_FORMAT = 'Y-m-d';

    /**
     * 到達した日(Y-m-d)。
     */
    public function reachedOn(DateTimeInterface $reachedAt): string
    {
        return CarbonImmutable::instance($reachedAt)->setTimezone(AccessContext::TIMEZONE)->format(self::DATE_FORMAT);
    }

    /**
     * 期限日(Y-m-d。期限の日数が無ければ null)。
     */
    public function dueOn(DateTimeInterface $reachedAt, ?int $deadlineDays): ?string
    {
        if ($deadlineDays === null) {
            return null;
        }

        return self::day($this->reachedOn($reachedAt))->addDays($deadlineDays)->format(self::DATE_FORMAT);
    }

    /**
     * 経過日数。
     */
    public function elapsedDays(DateTimeInterface $reachedAt, DateTimeInterface $today): int
    {
        return (int) self::day($this->reachedOn($reachedAt))->diffInDays(self::day($today->format(self::DATE_FORMAT)));
    }

    /**
     * 期限を超過したか。
     */
    public function isOverdue(?string $dueOn, DateTimeInterface $today): bool
    {
        return $dueOn !== null && $today->format(self::DATE_FORMAT) > $dueOn;
    }

    /**
     * 案件ごとの経過日数・期限日・超過(渡すノードの集まりは呼び手が選ぶ)。
     *
     * @param  list<CurrentNode>  $nodes
     */
    public function summaryOf(array $nodes, DateTimeInterface $today): CaseDeadline
    {
        if ($nodes === []) {
            return new CaseDeadline(null, null, false);
        }

        $elapsed = max(array_map(fn (CurrentNode $node) => $this->elapsedDays($node->reachedAt, $today), $nodes));
        $dues = array_values(array_filter(array_map(fn (CurrentNode $node) => $node->dueOn, $nodes), 'is_string'));
        $earliest = $dues === [] ? null : min($dues);

        return new CaseDeadline($elapsed, $earliest, $this->isOverdue($earliest, $today));
    }

    /**
     * 暦日の比べ方をタイムゾーンに依らせないため、日付を UTC の 0 時として作る。
     *
     * @throws LogicException 暦日として読めないとき(この class が作った値だけを渡すので起きない)
     */
    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $date, 'UTC')
            ?? throw new LogicException("暦日として読めない: {$date}");
    }
}
