<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Queries\Workflow\CaseDeadline;
use App\Queries\Workflow\CaseDeadlines;
use App\Queries\Workflow\CurrentNode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * 到達日時・期限日・経過日数の計算(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.4 節、同時実行と検査.md の 14.2 節の
 * 「期限と経過日数」のうち計算の分)。DB を使わずに確かめる(案件の操作から作る場面は Feature の CurrentNodesDeadlineTest)。期待値は 1.4 節の式から書いた。
 * 今日は呼び手が渡す基準日(Asia/Tokyo の 0 時。日付だけを使う)。
 */
final class CaseDeadlinesTest extends TestCase
{
    /**
     * 期限日の当日は超過でなく、翌日は超過。経過日数は到達した当日が 0 日。
     */
    public function test_the_due_date_itself_is_not_overdue_and_the_next_day_is(): void
    {
        $deadlines = new CaseDeadlines;
        $reachedAt = CarbonImmutable::parse('2026-10-13 01:00:00', 'UTC');
        $dueOn = $deadlines->dueOn($reachedAt, 3);

        $this->assertSame('2026-10-16', $dueOn);
        $this->assertSame([false, false, true], [
            $deadlines->isOverdue($dueOn, self::today('2026-10-13')),
            $deadlines->isOverdue($dueOn, self::today('2026-10-16')),
            $deadlines->isOverdue($dueOn, self::today('2026-10-17')),
        ]);
        $this->assertSame([0, 3, 4], [
            $deadlines->elapsedDays($reachedAt, self::today('2026-10-13')),
            $deadlines->elapsedDays($reachedAt, self::today('2026-10-16')),
            $deadlines->elapsedDays($reachedAt, self::today('2026-10-17')),
        ]);
    }

    /**
     * 期限 0 日は到達した日が期限。期限の日数が無ければ期限なし(超過しない)。
     */
    public function test_zero_days_is_due_on_the_reached_day_and_no_days_is_never_due(): void
    {
        $deadlines = new CaseDeadlines;
        $reachedAt = CarbonImmutable::parse('2026-10-13 01:00:00', 'UTC');

        $this->assertSame('2026-10-13', $deadlines->dueOn($reachedAt, 0));
        $this->assertTrue($deadlines->isOverdue('2026-10-13', self::today('2026-10-14')));
        $this->assertNull($deadlines->dueOn($reachedAt, null));
        $this->assertFalse($deadlines->isOverdue(null, self::today('2030-01-01')));
    }

    /**
     * 日付の境は Asia/Tokyo: UTC で 10 月 12 日 15:30 の到達は、Tokyo の 10 月 13 日 0:30(到達した日 = 13 日)。14:59 は 12 日。
     */
    public function test_the_day_boundary_is_asia_tokyo(): void
    {
        $deadlines = new CaseDeadlines;
        $afterBoundary = CarbonImmutable::parse('2026-10-12 15:30:00', 'UTC');
        $beforeBoundary = CarbonImmutable::parse('2026-10-12 14:59:00', 'UTC');

        $reachedOn = [$deadlines->reachedOn($afterBoundary), $deadlines->reachedOn($beforeBoundary)];
        $dueOn = [$deadlines->dueOn($afterBoundary, 1), $deadlines->dueOn($beforeBoundary, 1)];

        $this->assertSame(['2026-10-13', '2026-10-12'], $reachedOn);
        $this->assertSame(['2026-10-14', '2026-10-13'], $dueOn);
        $this->assertSame([0, 1], [
            $deadlines->elapsedDays($afterBoundary, self::today('2026-10-13')),
            $deadlines->elapsedDays($beforeBoundary, self::today('2026-10-13')),
        ]);
    }

    /**
     * 案件ごとの値 = 最も長い経過日数・最も早い期限日・どれかの超過。期限の日数を持たないノードは期限に数えない。
     */
    public function test_the_case_value_takes_the_longest_elapsed_days_and_the_earliest_due_date(): void
    {
        $deadlines = new CaseDeadlines;
        $nodes = [
            self::node('2026-10-10 01:00:00', '2026-10-20'),
            self::node('2026-10-05 01:00:00', null),
            self::node('2026-10-12 01:00:00', '2026-10-12'),
        ];

        $nextDay = $deadlines->summaryOf($nodes, self::today('2026-10-13'));
        $dueDay = $deadlines->summaryOf($nodes, self::today('2026-10-12'));

        $this->assertEquals(new CaseDeadline(8, '2026-10-12', true), $nextDay);
        $this->assertEquals(new CaseDeadline(7, '2026-10-12', false), $dueDay);
    }

    /**
     * 集まりが空なら経過日数・期限は「—」で超過でない。期限の日数を持つノードが無ければ期限は「—」で超過でない(経過日数は出す)。
     */
    public function test_an_empty_set_and_a_set_without_deadlines_show_a_dash(): void
    {
        $deadlines = new CaseDeadlines;

        $this->assertEquals(new CaseDeadline(null, null, false), $deadlines->summaryOf([], self::today('2026-10-13')));
        $this->assertEquals(
            new CaseDeadline(2, null, false),
            $deadlines->summaryOf([self::node('2026-10-11 01:00:00', null)], self::today('2026-10-13')),
        );
    }

    /**
     * 基準日 d(呼び手が渡す今日)= その日の Asia/Tokyo の 0 時。
     */
    private static function today(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Tokyo');
    }

    private static function node(string $reachedAtUtc, ?string $dueOn): CurrentNode
    {
        $reachedAt = CarbonImmutable::parse($reachedAtUtc, 'UTC');

        $kind = WorkflowNodeKind::Approve;

        return new CurrentNode(1, '承認', $kind, WorkflowNodeState::Waiting, null, null, [], $reachedAt, $dueOn);
    }
}
