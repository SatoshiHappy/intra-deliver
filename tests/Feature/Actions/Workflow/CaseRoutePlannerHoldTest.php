<?php

namespace Tests\Feature\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\RunsRoutePlans;
use Tests\Support\TransactionalTestCase;

/**
 * 計画は保留のノードを現在のノードとして読む(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.3 節。現在のノードの状態の一覧
 * = WorkflowNodeState::current() の 1 か所): 保留のノードを処理済みにでき、合議の可決・案件の完了で残った保留のノードを省き、処理対象者を保つ。
 * 保留の行は業務処理を通さず状態を書き換えて作る。ルート = R1(予算 100 = 部長を通らない)、合議は人数 2 で可決。
 */
final class CaseRoutePlannerHoldTest extends TransactionalTestCase
{
    use RunsRoutePlans;

    public function test_a_held_node_can_be_processed_and_keeps_its_targets_while_held(): void
    {
        $case = $this->councilCase();
        $this->hold($case, 'council.1');

        $this->assertSame('hold[u0004]', $this->shape($case)['council.1']);

        $this->step($case, 'council.1', WorkflowAction::Approve, 'u0004');

        $this->assertSame('done', $this->shape($case)['council.1']);
    }

    public function test_passing_the_council_skips_a_held_child(): void
    {
        $case = $this->councilCase();
        $this->hold($case, 'council.3');
        $this->step($case, 'council.1', WorkflowAction::Approve, 'u0004');

        $this->step($case, 'council.2', WorkflowAction::Approve, 'u0005');

        $this->assertSame(
            ['passed', 'skipped', 'waiting[u0007]'],
            $this->states($case, 'council', 'council.3', 'owner'),
        );
    }

    public function test_completing_the_case_skips_a_held_node(): void
    {
        $case = $this->councilCase();
        $this->hold($case, 'council.3');

        $this->step($case, 'council.1', WorkflowAction::ApproveEarly, 'u0004');

        $this->assertSame(['skipped', 'skipped', 'done'], $this->states($case, 'council', 'council.3', 'council.1'));
    }

    /**
     * 課長が合議(u0004・u0005・u0009)と責任者 u0007 を指定して承認した後の案件(合議の子 3 人が承認待ち)。
     */
    private function councilCase(): WorkflowCase
    {
        $case = $this->caseOf($this->addVersion($this->r1()), values: ['budget' => 100]);
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->step($case, 'section_chief', WorkflowAction::Approve, 'u0002', [
            'council' => ['u0004', 'u0005', 'u0009'],
            'owner' => ['u0007'],
        ]);

        return $case;
    }

    private function hold(WorkflowCase $case, string $label): void
    {
        WorkflowCaseNode::query()->whereKey($this->labelIds($case)[$label])
            ->update(['state' => WorkflowNodeState::Hold]);
    }

    /**
     * @return list<string>
     */
    private function states(WorkflowCase $case, string ...$labels): array
    {
        $shape = $this->shape($case);

        return array_map(fn (string $label) => $shape[$label], array_values($labels));
    }
}
