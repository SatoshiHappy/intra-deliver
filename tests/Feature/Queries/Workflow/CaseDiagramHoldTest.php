<?php

namespace Tests\Feature\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use Tests\Support\OperatesHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の詳細の保留中のノード(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節): 図・表・合議の進み具合の行の stepState は、
 * 保留中のノードで state が hold・語は「保留中」。ほかのノードの stepState は変わらない。
 * ホームの経路の保留中は current のまま(WorkflowHomeDeadlineTest・WorkflowHomeSectionsTest)。
 * 版 3 と同じ形のルート(R1)に本物の業務処理で申請・保留して作る。
 */
final class CaseDiagramHoldTest extends TransactionalTestCase
{
    use OperatesHolds;

    /**
     * 課長を u0002 が保留すると、図・表の課長は hold・「保留中」、まだ到達していない責任者は変わらない。
     */
    public function test_a_held_node_carries_the_hold_state(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->holdOn($case, 'section_chief', 'u0002');

        $nodes = $this->stepStatesOf($case, 'u0001');

        $this->assertSame(['hold', '保留中'], $nodes['課長']);
        $this->assertSame(['upcoming', '到達していない'], $nodes['責任者']);
    }

    /**
     * 合議の子 1 を u0004 が保留すると、図の子の箱も進み具合の行も hold・「保留中」、保留していない子の行は承認待ちのまま。
     */
    public function test_a_held_council_child_carries_the_hold_state_in_its_row(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $assignments = ['council' => ['u0004', 'u0005', 'u0009'], 'owner' => ['u0011']];
        $this->approve($case, 'section_chief', 'u0002', $assignments);
        $this->holdOn($case, 'council.1', 'u0004');

        $detail = $this->show('u0004', $case);

        $this->assertSame(['hold', '保留中'], $this->stepStatesOf($case, 'u0004')['関係部署の合議 承認 1']);
        $this->assertSame(
            [['hold', '保留中'], ['current', '承認待ち'], ['current', '承認待ち']],
            array_map(fn (array $row) => array_values($row['stepState']), $detail['councils'][0]['rows']),
        );
    }

    /**
     * ノードの表示名 → [stepState の state, 語]。
     *
     * @return array<string, array{string, string}>
     */
    private function stepStatesOf(WorkflowCase $case, string $viewer): array
    {
        $states = [];

        foreach ($this->show($viewer, $case)['nodes'] as $node) {
            $states[$node['name']] = [$node['stepState']['state'], $node['stepState']['label']];
        }

        return $states;
    }

    /**
     * @return array<string, mixed>
     */
    private function show(string $viewer, WorkflowCase $case): array
    {
        return app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser($viewer)), $case->id);
    }
}
