<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\HoldCase;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\TransferCase;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CaseDeadline;
use App\Queries\Workflow\CaseDeadlines;
use App\Queries\Workflow\CurrentNode;
use App\Queries\Workflow\CurrentNodes;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 現在のノードの到達日時と期限日(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.3 節・1.4 節、同時実行と検査.md の 14.2 節の
 * 「期限と経過日数」): reachedAt = 列 reached_at、dueOn = 到達した日(Asia/Tokyo)+ 写した期限の日数。保留中も数え、振替で変わらず、
 * 差戻し・引戻しで到達し直すと数え直す。フロー = 申請(u0001)→ 承認 1(u0002)→ 承認 2(u0003)。
 */
final class CurrentNodesDeadlineTest extends TransactionalTestCase
{
    use RunsWorkflowOperations;

    public function test_reached_at_and_due_on_follow_the_columns_and_the_formula(): void
    {
        $case = WorkflowCase::factory()->active()->createOne();
        $tokyoNextDay = CarbonImmutable::parse('2026-10-12 15:30:00', 'UTC');
        $sameDay = CarbonImmutable::parse('2026-10-12 01:00:00', 'UTC');
        $withDays = $this->currentNode($case, 2, WorkflowNodeState::Waiting, $tokyoNextDay, 2);
        $withoutDays = $this->currentNode($case, 3, WorkflowNodeState::Waiting, $sameDay, null);
        $held = $this->currentNode($case, 4, WorkflowNodeState::Hold, $sameDay, 0);

        $this->assertSame([
            [$withDays->id, $tokyoNextDay->toIso8601String(), WorkflowNodeState::Waiting, '2026-10-15'],
            [$withoutDays->id, $sameDay->toIso8601String(), WorkflowNodeState::Waiting, null],
            [$held->id, $sameDay->toIso8601String(), WorkflowNodeState::Hold, '2026-10-12'],
        ], $this->rows($case));
    }

    /**
     * 保留中も数え(期限超過も出る)、振替では到達日時が変わらない。差戻しで戻ったノード・引戻しで戻ったノードは、その時から数え直す。
     */
    public function test_hold_and_transfer_keep_the_count_and_send_back_and_pull_back_restart_it(): void
    {
        $this->createFlow();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00:00'));
        $case = $this->applyAs($this->seededUser('u0001'));
        $first = $this->caseNode($case, 'approval1');
        $first->update(['deadline_days' => 2]);
        $reachedAt = now()->toIso8601String();

        $waiting = WorkflowNodeState::Waiting;
        $context = $this->on('2026-10-16', 'u0002');
        app(HoldCase::class)->execute($context, $case->id, $this->revisionOf($case), $first->id, null);
        $this->assertSame([[$first->id, $reachedAt, WorkflowNodeState::Hold, '2026-10-15']], $this->rows($case));
        $this->assertEquals(new CaseDeadline(3, '2026-10-15', true), $this->summary($case, '2026-10-16'));

        $to = $this->seededUser('u0006')->id;
        $context = $this->on('2026-10-17', 'u0002');
        app(TransferCase::class)->execute($context, $case->id, $this->revisionOf($case), $first->id, $to, null);
        $this->assertSame([[$first->id, $reachedAt, $waiting, '2026-10-15']], $this->rows($case));

        $context = $this->on('2026-10-18', 'u0006');
        app(ApproveCase::class)->execute($context, $case->id, $this->revisionOf($case), $first->id, null);
        $this->on('2026-10-19', 'u0003');
        $this->sendBack($this->seededUser('u0003'), $case, 'approval1', date: '2026-10-19');
        $this->assertSame([[$first->id, now()->toIso8601String(), $waiting, '2026-10-21']], $this->rows($case));

        $this->on('2026-10-20', 'u0003');
        $this->operate(PullBackCase::class, $this->seededUser('u0003'), $case, date: '2026-10-20');
        $second = $this->caseNode($case, 'approval2');
        $this->assertSame([[$second->id, now()->toIso8601String(), $waiting, null]], $this->rows($case));
    }

    /**
     * 時計をその日の 3 時(UTC。Asia/Tokyo の同じ日の 12 時)に進め、その日を基準日にした判定の入力を返す。
     */
    private function on(string $date, string $userCode): AccessContext
    {
        $this->travelTo(CarbonImmutable::parse("{$date} 03:00:00"));

        return $this->contextOf($this->seededUser($userCode), date: $date);
    }

    /**
     * @return list<array{int, string, WorkflowNodeState, string|null}> [id, 到達日時(UTC), 状態, 期限日]
     */
    private function rows(WorkflowCase $case): array
    {
        $row = fn (CurrentNode $node) => [
            $node->id, $node->reachedAt->utc()->toIso8601String(), $node->state, $node->dueOn,
        ];

        return array_map($row, app(CurrentNodes::class)->of([$case->id])[$case->id]);
    }

    private function summary(WorkflowCase $case, string $today): CaseDeadline
    {
        $nodes = app(CurrentNodes::class)->of([$case->id])[$case->id];

        return app(CaseDeadlines::class)->summaryOf($nodes, CarbonImmutable::parse($today, 'Asia/Tokyo'));
    }

    private function currentNode(
        WorkflowCase $case,
        int $position,
        WorkflowNodeState $state,
        CarbonImmutable $reachedAt,
        ?int $days,
    ): WorkflowCaseNode {
        return WorkflowCaseNode::factory()->createOne([
            'workflow_case_id' => $case->id, 'position' => $position, 'state' => $state,
            'reached_at' => $reachedAt, 'deadline_days' => $days,
        ]);
    }
}
